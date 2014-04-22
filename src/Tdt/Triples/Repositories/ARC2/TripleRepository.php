<?php

namespace Tdt\Triples\Repositories\ARC2;

use Tdt\Triples\Repositories\Interfaces\TripleRepositoryInterface;
use Tdt\Triples\Repositories\Interfaces\SemanticSourceRepositoryInterface;

class TripleRepository implements TripleRepositoryInterface
{

    protected $semantic_sources;

    private static $graph_name = "http://cachedtriples.foo/";

    public function __construct(SemanticSourceRepositoryInterface $semantic_sources)
    {
        $this->semantic_sources = $semantic_sources;
    }

    /**
     * Return all triples with a subject that equals the base uri
     *
     * @param string $base_uri
     * @param integer $limit
     * @param integer $offset
     *
     * @return EasyRdf_Graph
     */
    public function getTriples($base_uri, $limit = 5000, $offset = 0)
    {
        $count_arc_triples = $this->countARC2Triples($base_uri);

        $query = $this->createSparqlQuery($base_uri, $limit, $offset);

        $store = $this->setUpArc2Store();

        $result = $store->query($query);

        // Fetch the template parameters from the request
        list($p, $o) = $this->getTemplateParameters();

        // The result will be in an array structure, we need to build an
        // EasyRdf_Graph out of this

        $graph = $this->buildGraph($result['result']);

        if (!$result) {
            $message = array_shift($store->getErrors());

            \Log::error(500, "Something went wrong while fetching triples from the store: ");
        }

        // Fetch data out of the sparql endpoint as well,
        // if necessary according to the paging parameters
        // What we are trying to accomplish is a simulated paging mechanism
        // But since sparql sources aren't cached, this mechanism will
        // have to be simulated.
        $count_arc_triples = $this->countARC2Triples($base_uri);

        // Total amount of triples
        $total_triples_count = $this->getCount($base_uri);

        $triples_fetched = $count_arc_triples - $offset;

        if ($count_arc_triples < $offset + $limit) {

            $total_triples = 0;

            if ($count_arc_triples > $offset) {
                $total_triples = $count_arc_triples - $offset;
            }

            // If the fetched triples are negative, this means that we didnt fetch any triples at all
            if ($triples_fetched < 0) {
                $offset = $triples_fetched * -1;
                $graph = new EasyRdf_Graph();
            } else {
                // The entire offset has been met in the ARC2 store
                $offset = 0;
            }

            // For every semantic source, count the triples we'll get out of them
            $sparql_repo = \App::make('Tdt\Triples\Repositories\Interfaces\SparqlSourceRepositoryInterface');

            foreach ($sparql_repo->getAll() as $sparql_source) {

                if ($total_triples < $limit) {

                    $endpoint = $sparql_source['endpoint'];
                    $pw = $sparql_source['endpoint_password'];
                    $user = $sparql_source['endpoint_user'];

                    $endpoint = rtrim($endpoint, '/');

                    // Prepare the count query

                    list($p, $o) = $this->getTemplateParameters();

                    $count_query = '';

                    if ($p == '?p' && $o == '?o') {
                        $count_query = "select (count(*) AS ?count) WHERE { <$base_uri> ?p ?o. OPTIONAL {?o ?p1 ?o1. ?o1 ?p2 ?o3. }}";
                    } else {
                        $count_query = "select (count(*) AS ?count) WHERE { <$base_uri> $p $o }";
                    }

                    $count_query = urlencode($count_query);
                    $count_query = str_replace("+", "%20", $count_query);

                    $query_uri = $endpoint . '?query=' . $count_query . '&format=' . urlencode("application/sparql-results+json");

                    $result = $this->executeUri($query_uri, $user, $pw);

                    $response = json_decode($result);

                    if (!empty($response)) {

                        $count = $response->results->bindings[0]->count->value;

                        // If the amount of matching triples is higher than the offset
                        // add them and update the offset, if not higher, then only update the offset

                        if ($count > $offset) {

                            // Read the triples from the sparql endpoint
                            $query_limit = $limit - $total_triples;

                            $query = $this->createSparqlQuery($base_uri, $query_limit, $offset);

                            $query = urlencode($query);
                            $query = str_replace("+", "%20", $query);

                            $query_uri = $endpoint . '?query=' . $query . '&format=' . urlencode("application/rdf+xml");

                            $result = $this->executeUri($query_uri, $user, $pw);

                            if (!empty($result) && $result[0] == '<') {

                                // Parse the triple response and retrieve the triples from them
                                $result_graph = new \EasyRdf_Graph();
                                $parser = new \EasyRdf_Parser_RdfXml();

                                $parser->parse($result_graph, $result, 'rdfxml', null);

                                $graph = $this->mergeGraph($graph, $result_graph);

                                $total_triples += $count - $offset;

                            } else {
                                \Log::error("Something went wrong while fetching the triples from a sparql source. The error was " . $result . ". The query was : " . $query_uri);
                            }

                        } else {
                            // Update the offset
                            $offset -= $count;
                        }

                        if ($offset < 0) {
                            $offset = 0;
                        }
                    }
                }
            }
        }

        // Add the void and hydra triples to the resulting graph
        $graph = $this->addMetaTriples($base_uri, $graph, $total_triples_count);

        return $graph;
    }

    /**
     * Store (=cache) triples into a triplestore (or equivalents) for optimization
     *
     * @param integer $id   The id of the configured semantic source
     * @param array $config The configuration needed to extract the triples
     */
    public function cacheTriples($id, array $config)
    {
        // Fetch the ARC2 triplestore
        $store = $this->setUpArc2Store();

        // Fetch the data extractor for the given type
        $type = $config['type'];

        $source_type = strtolower($type);

        $graph = '';

        $caching_necessary = true;

        switch ($source_type) {
            case 'turtle':

                $rdf_reader = \App::make('\Tdt\Core\DataControllers\RDFController');

                $configuration = array(
                    'uri' => $config['uri'],
                    'format' => 'turtle',
                );

                $data = $rdf_reader->readData($configuration, array());
                $graph = $data->data;

                break;
            case 'rdf':

                $rdf_reader = \App::make('\Tdt\Core\DataControllers\RDFController');

                $configuration = array(
                    'uri' => $config['uri'],
                    'format' => 'xml',
                );

                $data = $rdf_reader->readData($configuration, array());
                $graph = $data->data;

                break;
            case 'sparql':

                // Do nothing, the sparql endpoint is already optimized for read operations
                $caching_necessary = false;

                break;
            default:
                \App::abort(
                    400,
                    "The source type, $source_type, was configured, but no reader has been found
                    to extract semantic data from it."
                );

                break;
        }

        if ($caching_necessary) {

            // Make the graph name to cache the triples into
            $graph_name = self::$graph_name . $id;

            // Serialise the triples into turtle
            $ttl = $graph->serialise('turtle');

            // Parse the turlte into an ARC graph
            $arc_parser = \ARC2::getTurtleParser();

            $ser = \ARC2::getNTriplesSerializer();

            // Parse the turtle string
            $arc_parser->parse('', $ttl);

            // Serialize the triples again, this is because an EasyRdf_Graph has
            // troubles with serializing unicode. The underlying bytes are
            // not properly converted to utf8 characters by our serialize function
            // A dump shows that all unicode encodings through serialization are the same (in easyrdf and arc)
            // however when we convert the string (binary) into a utf8, only the arc2 serialization
            // comes out correctly, hence something beneath the encoding (byte sequences?) must hold some wrongs.
            $triples = $ser->getSerializedTriples($arc_parser->getTriples());

            preg_match_all("/(<.*\.)/", $triples, $matches);

            $triples_buffer = array();

            if ($matches[0]) {
                $triples_buffer = $matches[0];
            }

            \Log::info("--------------- CACHING TRIPLES -------------------------");
            \Log::info("Starting insertion of triples into the ARC2 RDF Store into the graph with the name " . $graph_name);

            // Insert the triples in a chunked manner (not all triples at once)
            $buffer_size = 20;

            while (count($triples_buffer) >= $buffer_size) {

                $triples_to_cache = array_slice($triples_buffer, 0, $buffer_size);

                \Log::info("Caching " . count($triples_to_cache) . " triples into the store.");

                $this->addTriples($graph_name, $triples_to_cache, $store);

                $triples_buffer = array_slice($triples_buffer, $buffer_size);
            }

            // Insert the last triples in the buffer
            \Log::info("Caching " . count($triples_buffer) . " triples into the store.");

            $this->addTriples($graph_name, $triples_buffer, $store);

            \Log::info("--------------- DONE CACHING TRIPLES -------------------");
        }
    }

    /**
     * Insert triples into the triple store
     *
     * @param string $graph_name The graph name of the graph to store the triples into
     * @param array  $triples    The triples that need to be stored
     * @param mixed  $store      The store in which the triples will go
     *
     * @return void
     */
    private function addTriples($graph_name, $triples, $store)
    {
        $triples_string = implode(' ', $triples);

        $serialized = $this->serialize($triples_string);

        \Log::info("Inserting " . count($triples) . " triples into the triple store.");

        $query = $this->createInsertQuery($graph_name, $serialized);

        // Execute the query
        $result = $store->query($query);

        // If the insert fails, insert every triple one by one
        if (!$result) {

            \Log::warning("Inserting a chunk of the triples from the buffer failed. Every triple will be inserted separately.");

            $totalTriples = count($triples);

            // Insert every triple one by one
            foreach ($triples as $triple) {

                $serialized = $this->serialize($triple);

                $query = $this->createInsertQuery($graph_name, $serialized);

                // Execute the query
                $result = $store->query($query);

                // TODO logging
                if (!$result) {
                    \Log::error("Inserting the triple (" . $triple . ") failed, please make sure that it's a valid triple.");
                } else {
                    \Log::info("Successfully insert triple: " . $triple);
                }
            }
        }
    }

    /**
     * Initialize the ARC2 MySQL triplestore (if necessary) and return the instance
     * https://github.com/semsol/arc2/wiki/Using-ARC%27s-RDF-Store
     *
     * @return mixed
     */
    private function setUpArc2Store()
    {
        // Get the MySQL configuration, abort when not applicable
        $mysql_config = \Config::get('database.connections.mysql');

        if (empty($mysql_config) || $mysql_config['driver'] != 'mysql') {
            \App::abort(404, "No configuration for a MySQL connection was found. This is obligatory for the tdt/triples package.");
        }

        // Set up the configuration for the arc2 store
        $config = array(
            'db_host' => $mysql_config['host'],
            'db_name' => $mysql_config['database'],
            'db_user' => $mysql_config['username'],
            'db_pwd' => $mysql_config['password'],
            'store_name' => $mysql_config['prefix'],
            );

        $store = \ARC2::getStore($config);

        // Check if the store has been setup
        if (!$store->isSetUp()) {
            $store->setUp();
        }

        return $store;
    }

    /**
     * Create an insert SPARQL query based on the graph id
     *
     * @param string $graph_name The graph in which the triples will go
     * @param string $triples    The triples that need to be stored
     *
     * @return string
     */
    private function createInsertQuery($graph_name, $triples)
    {
        $query = "INSERT INTO <$graph_name> {";
        $query .= $triples;
        $query .= ' }';

        return $query;
    }

    /**
     * Remove the cached triples coming from a certain semantic source
     *
     * @param integer $id The id of the semantic source configuration
     */
    public function removeTriples($id)
    {
        $graph_name = self::$graph_name . $id;

        $delete_query = "DELETE FROM <$graph_name>";

        $store = $this->setUpArc2Store();

        $result = $store->query($delete_query, 'raw');

        \Log::info("The triples from the graph " . $graph_name . " have been deleted.");

        if (!$result) {
            \Log::warning("The delete query that deletes triples from graph with id $id, encountered an error.");
        }
    }

    /**
     * Serialize triples to a format acceptable for a triplestore endpoint (utf8 chacracters)
     * @param string $triples
     *
     * @return string
     */
    private function serialize($triples)
    {
        $serialized_triples = preg_replace_callback(
            '/(?:\\\\u[0-9a-fA-Z]{4})+/',
            function ($v) {
                $v = strtr($v[0], array('\\u' => ''));
                return mb_convert_encoding(pack('H*', $v), 'UTF-8', 'UTF-16BE');
            },
            $triples
        );

        return $serialized_triples;
    }

    /**
     * Creates a query that fetches all of the triples
     * of which the subject matches the base uri
     *
     * @param string $base_uri
     *
     * @return string
     */
    private function createSparqlQuery($base_uri, $limit = 5000, $offset = 0, $depth = 3)
    {

        list($p, $o) = $this->getTemplateParameters();

        $vars = '<'. $base_uri .'> ' . $p . ' ' . $o . '.';

        $last_object = $o;
        $depth_vars = '';

        $construct_statement = '';
        $filter_statement = '';

        // Only when no template parameter is given, add the depth parameters
        if ($p == '?p' && $o == '?o') {

            for ($i = 2; $i <= $depth; $i++) {

                $depth_vars .= $last_object . ' ?p' . $i . ' ?o' . $i . '. ';

                $last_object = '?o' . $i;
            }

            $construct_statement = 'construct {' . $vars . $depth_vars . '}';
            $filter_statement = '{'. $vars . 'OPTIONAL { ' . $depth_vars . '}}';

        } else {

            $construct_statement = 'construct {' . $vars . ' }';
            $filter_statement = '{'. $vars . ' }';
        }

        return $construct_statement . $filter_statement . ' offset ' . $offset . ' limit ' . $limit;
    }

    /**
     * Get the template parameters from the request (predicate, object)
     * predicate defaults to ?p and object to ?o
     *
     * @return array
     */
    private function getTemplateParameters()
    {
        return array(\Input::get('predicate', '?p'), \Input::get('object', '?o'));
    }


    /**
     * Create an EasyRdf_Graph out of an ARC2 query result structure
     *
     * @param array $result
     *
     * @return EasyRdf_Graph
     */
    private function buildGraph(array $result)
    {
        $graph = new \EasyRdf_Graph();

        //dd($result);

        $triples_buffer = array();

        // Build a string out of the result, we know it's always 3 levels deep
        $ttl_string = '';

        foreach ($result as $s => $p_arr) {

            foreach ($p_arr as $p => $o_arr) {

                $triple_string = '<' . $s . '> ';

                if (filter_var($p, FILTER_VALIDATE_URL) === false) {
                    $triple_string .= $p . ' ';
                } else {
                    $triple_string .= '<' . $p . '> ';
                }

                foreach ($o_arr as $key => $val) {

                    $triple = $triple_string;

                    if ($val['type'] == "uri") {
                        $triple .= '<' . $val['value'] . '> .';
                    } else { //literal
                        if (!empty($val['lang'])) {
                            $triple .= '"' . $val['value'] . '"@' . $val['lang'] . '.';
                        } else if (!empty($val['datatype'])) {
                            $triple .= '"' . $val['value'] . '"^^<' . $val['datatype'] . '>.';
                        } else { // Blank node
                            $triple .= ' ' . $val['value'] . '.';
                        }
                    }

                    array_push($triples_buffer, $triple);
                }
            }
        }

        $ttl_string = implode(' ', $triples_buffer);

        $parser = new \EasyRdf_Parser_Turtle();

        $parser->parse($graph, $ttl_string, 'turtle', '');

        return $graph;
    }

    /**
     * Add void and hydra meta-data to an existing graph
     *
     * @param string        $base_uri The URI of the request
     * @param EasyRdf_Graph $graph    The graph to which meta data has to be added
     * @param integer       $count    The total amount of triples that match the URI
     *
     * @return EasyRdf_Graph $graph
     */
    private function addMetaTriples($base_uri, $graph, $count)
    {
        // Add the void and hydra namespace to the EasyRdf framework
        \EasyRdf_Namespace::set('hydra', 'http://www.w3.org/ns/hydra/core#');
        \EasyRdf_Namespace::set('void', 'http://rdfs.org/ns/void#');
        \EasyRdf_Namespace::set('dcterms', 'http://purl.org/dc/terms/');

        // Add the meta data semantics to the graph
        $root = \Request::root();
        $root .= '/';

        //\EasyRdf_Namespace::set('', $root);

        $graph = new \EasyRdf_Graph();

        // Count the graph triples without the meta-data we add here
        $total_graph_triples = $graph->countTriples();

        $identifier = str_replace($root, '', $base_uri);

        $graph->addResource($base_uri, 'a', 'void:Dataset');
        $graph->addResource($base_uri, 'a', 'hydra:Collection');

        $resource = $graph->resource($base_uri);
        $mapping = $graph->resource('hydra:mapping');
        $mapping->addLiteral('hydra:template', $base_uri . '{predicate, object}');
        $graph->addResource($resource, 'hydra:search', $mapping);

        // _:triplePattern
        /*$triple_pattern = $graph->resource('_:triplePattern');
        $triple_pattern->addResource('hydra:template', $base_uri . '{predicate, object}');
        $triple_pattern->addResource('hydra:mapping', '_:subject');
        $triple_pattern->addResource('hydra:mapping', '_:predicate');
        $triple_pattern->addResource('hydra:mapping', '_:object');

        // _:subject
        $subject = $graph->resource('_:subject');
        $subject->addLiteral('hydra:variable', 'subject');
        $subject->addResource('hydra:property', 'rdf:subject');

        // _:predicate
        $predicate = $graph->resource('_:predicate');
        $predicate->addLiteral('hydra:variable', 'predicate');
        $predicate->addResource('hydra:property', 'rdf:predicate');

        // _:object
        $object = $graph->resource('_:object');
        $object->addLiteral('hydra:variable', 'predicate');
        $object->addResource('hydra:property', 'rdf:object');

        // Add the result meta-data
        $graph->addResource($base_uri, 'a', 'hydra:Collection');
        $graph->addResource($base_uri, 'a', 'hydra:PagedCollection');*/
        $graph->addResource($base_uri, 'dcterms:title', 'A linked dataset');

        // Add the pattern
        $pattern = "Semantic dataset containing triples matching the pattern { <" . $base_uri . "> ?p ?o}";
        $graph->addLiteral($base_uri, 'dcterms:description', $pattern);
        $graph->addLiteral($base_uri, 'hydra:endpoint', ":$identifier");
        $graph->addLiteral($base_uri, 'hydra:totalItems', \EasyRdf_Literal::create($count, null, 'xsd:integer'));
        $graph->addLiteral($base_uri, 'void:triples', \EasyRdf_Literal::create($total_graph_triples));

        return $graph;
    }

    /**
     * Merge two graphs and return the result
     *
     * @param EasyRdf_Graph $graph
     * @param EasyRdf_Graph $input_graph
     *
     * @return EasyRdf_Graph
     */
    private function mergeGraph($graph, $input_graph)
    {
        $turtle_graph = $input_graph->serialise('turtle');

        $graph->parse($turtle_graph, 'turtle');

        return $graph;
    }

    /**
     * Return the total amount of triples that
     * have a subject that matches base_uri
     *
     * @param $base_uri
     *@
     * @return integer
     */
    public function getCount($base_uri)
    {
        $triples_amount = 0;

        $triples_amount += $this->countARC2Triples($base_uri);

        // Count the triples in the sparql sources (these aren't cached in our store)
        $sparql_repo = \App::make('Tdt\Triples\Repositories\Interfaces\SparqlSourceRepositoryInterface');

        foreach ($sparql_repo->getAll() as $sparql_source) {

            $endpoint = $sparql_source['endpoint'];
            $pw = $sparql_source['endpoint_password'];
            $user = $sparql_source['endpoint_user'];

            $endpoint = rtrim($endpoint, '/');

            list($p, $o) = $this->getTemplateParameters();

            $count_query = '';

            if ($p == '?p' && $o == '?o') {
                $count_query = "select (count(*) AS ?count) WHERE { <$base_uri> ?p ?o. OPTIONAL {?o ?p1 ?o1. ?o1 ?p2 ?o3. }}";
            } else {
                $count_query = "select (count(*) AS ?count) WHERE { <$base_uri> $p $o }";
            }

            $count_query = urlencode($count_query);
            $count_query = str_replace("+", "%20", $count_query);

            $query_uri = $endpoint . '?query=' . $count_query . '&format=' . urlencode("application/sparql-results+json");

            $result = $this->executeUri($query_uri, $user, $pw);

            $response = json_decode($result);

            if (!empty($response)) {

                $count = $response->results->bindings[0]->count->value;

                $triples_amount += $count;
            }
        }

        return $triples_amount;
    }

    /**
     * Count the amount of triples that are in the ARC2 store given a certain base_uri
     *
     * @param string $base_uri
     *
     * @return integer
     */
    private function countARC2Triples($base_uri)
    {
        list($p, $o) = $this->getTemplateParameters();

        $count_query = '';

        if ($p == '?p' && $o == '?o') {
            $count_query = "select (count(?o) AS ?count) WHERE { <$base_uri> ?p ?o. OPTIONAL {?o ?p1 ?o1. ?o1 ?p2 ?o3. }}";
        } else {
            $count_query = "select
                            (count(*) AS ?count)
                            WHERE { <$base_uri> $p $o }
                            ";
        }

        $store = $this->setUpArc2Store();

        $result = $store->query($count_query, 'raw');

        $arc2_triples_count = $result['rows'][0]['count'];

        return $arc2_triples_count;
    }

    /**
     * Execute a query using cURL and return the result.
     * This function will abort upon error.
     */
    private function executeUri($uri, $user = '', $password = '')
    {
        // Check if curl is installed on this machine
        if (!function_exists('curl_init')) {
            \App::abort(500, "cURL is not installed as an executable on this server, this is necessary to execute the SPARQL query properly.");
        }

        // Initiate the curl statement
        $ch = curl_init();

        // If credentials are given, put the HTTP auth header in the cURL request
        if (!empty($user)) {

            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_USERPWD, $user . ":" . $password);
        }

        // Set the request uri
        curl_setopt($ch, CURLOPT_URL, $uri);

        // Request for a string result instead of having the result being outputted
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Execute the request
        $response = curl_exec($ch);

        if (!$response) {
            $curl_err = curl_error($ch);
            \Log::error("Something went wrong while executing a count sparql query. The request we put together was: $uri.");
        }

        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // According to the SPARQL 1.1 spec, a SPARQL endpoint can only return 200,400,500 reponses
        if ($response_code == '400') {
            \Log::error("The SPARQL endpoint returned a 400 error. The error was: $response. The URI was: $uri");
        } elseif ($response_code == '500') {
            \Log::error("The SPARQL endpoint returned a 500 error. The URI was: $uri");
        }

        curl_close($ch);

        return $response;
    }
}