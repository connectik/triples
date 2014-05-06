<?php

namespace Tdt\Triples\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

/**
 * The ExecuteJobCommand class holds the functionality to execute a job
 *
 * @copyright (C) 2011,2013 by OKFN Belgium vzw/asbl
 * @license AGPLv3
 * @author Jan Vansteenlandt <jan@okfn.be>
 */
class CacheTriples extends Command
{
     /**
     * The console command name
     *
     * @var string
     */
    protected $name = 'triples:load';

    /**
     * The console command description
     *
     * @var string
     */
    protected $description = "Load data from a semantic source into our triple store. Old data belonging to the given configuration will be replaced by the new extracted data.";

    /**
     * Execute the console command
     *
     * @return void
     */
    public function fire()
    {
        // Fetch the id of the configuration
        $id = $this->argument('ID');

        $semantic_sources = \App::make('Tdt\Triples\Repositories\Interfaces\SemanticSourceRepositoryInterface');

        // Throws an error if the id isn't found, no need to reproduce
        $configuration = $semantic_sources->getSourceConfiguration($id);

        $triples = \App::make('Tdt\Triples\Repositories\Interfaces\TripleRepositoryInterface');

        $this->info("Configuration found, starting to load the triples from the source.");

        $this->info("Deleting triples from the graph, if they're already present.");

        // Delete the triples from the graph first
        $triples->removeTriples($configuration['id']);

        $this->info("Extracting triples from the semantic source.");

        // Cache the triples
        $triples->cacheTriples($id, $configuration);

        $this->info("All triples were successfully loaded into the store");
    }

    /**
     * Get the console command arguments
     *
     * @return array
     */
    protected function getArguments()
    {
        return array(
            array('ID', InputArgument::REQUIRED, 'ID of the semantic source configuration.'),
        );
    }

    /**
     * Get the console command options
     *
     * @return array
     */
    protected function getOptions()
    {
        return array(

        );
    }
}
