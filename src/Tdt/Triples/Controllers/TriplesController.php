<?php

namespace Tdt\Triples\Controllers;

use Tdt\Triples\Repositories\Interfaces\SemanticSourceRepositoryInterface;
use Tdt\Core\ContentNegotiator;
use Tdt\Core\Datasets\Data;

class TriplesController extends \Controller
{

    protected $semantic_source;

    public function __construct(SemanticSourceRepositoryInterface $semantic_source)
    {
        $this->semantic_source = $semantic_source;
    }

    public function handle($id = null)
    {
        // Delegate the request based on the used http method
        $method = \Request::getMethod();

        switch($method){
            case "PUT":
                return $this->put();
                break;
            case "GET":
                return $this->get();
                break;
            case "POST":
            case "PATCH":
                return $this->patch();
                break;
            case "DELETE":
                return $this->delete($id);
                break;
            case "HEAD":
                return $this->head();
                break;
            default:
                // Method not supported
                \App::abort(405, "The HTTP method '$method' is not supported by this resource.");
                break;
        }
    }

    public function get()
    {
        $sources = $this->semantic_source->getAllConfigurations();

        $result = new Data();
        $result->data = $sources;

        return ContentNegotiator::getResponse($result, 'json');
    }

    public function put()
    {
        // Retrieve the input from the request.
        $input = $this->fetchInput();

        $result = $this->semantic_source->store($input);

        $response = \Response::make("", 200);
        $response->header('Location', \URL::to('api/triples'));

        return $response;
    }

    public function patch()
    {
        \App::abort(405, "The HTTP method '$method' is not supported by this resource.");
    }

    public function head()
    {
        \App::abort(405, "The HTTP method '$method' is not supported by this resource.");
    }

    public function delete($id)
    {
        $result = $this->semantic_source->delete($id);

        if ($result) {
            $response = \Response::make("", 200);
        }else{
            $response = \Response::make("", 404);
        }

        return $response;
    }

    /**
     * Retrieve the input, make sure all keys are lowercased
     */
    private function fetchInput()
    {
        // Retrieve the parameters of the PUT requests (either a JSON document or a key=value string)
        $input = \Request::getContent();

        // Is the body passed as JSON, if not try getting the request parameters from the uri
        if (!empty($input)) {
            $input = json_decode($input, true);
        }else{
            $input = \Input::all();
        }

        // If input is empty, then something went wrong
        if (empty($input)) {
            \App::abort(400, "The parameters could not be parsed from the body or request URI, make sure parameters are provided and if they are correct (e.g. correct JSON).");
        }

        // Change all of the parameters to lowercase
        $input = array_change_key_case($input);

        return $input;
    }
}
