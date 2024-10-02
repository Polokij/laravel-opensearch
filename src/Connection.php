<?php

namespace PDPhilip\Elasticsearch;


use OpenSearch\Client;
use OpenSearch\ClientBuilder;
use PDPhilip\Elasticsearch\DSL\Bridge;

use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Support\Str;
use RuntimeException;


class Connection extends BaseConnection
{
    
    protected $client;
    protected $index;
    protected $maxSize;
    protected $indexPrefix;
    
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        if (!empty($config['index_prefix'])) {
            $this->indexPrefix = $config['index_prefix'];
        }
        
        $this->client = $this->buildConnection();
        
        $this->useDefaultPostProcessor();
        
        $this->useDefaultSchemaGrammar();
        
        $this->useDefaultQueryGrammar();
        
    }
    
    public function getIndexPrefix(): string
    {
        return $this->indexPrefix;
    }
    
    public function setIndexPrefix($newPrefix): void
    {
        $this->indexPrefix = $newPrefix;
    }
    
    
    public function getTablePrefix(): string
    {
        return $this->getIndexPrefix();
    }
    
    public function setIndex($index): string
    {
        $this->index = $index;
        if ($this->indexPrefix) {
            if (!(strpos($this->index, $this->indexPrefix.'_') !== false)) {
                $this->index = $this->indexPrefix.'_'.$index;
            }
        }
        
        return $this->getIndex();
    }
    
    public function getSchemaGrammar()
    {
        return new Schema\Grammar($this);
    }
    
    public function getIndex(): string
    {
        return $this->index;
    }
    
    public function setMaxSize($value)
    {
        $this->maxSize = $value;
    }
    
    
    public function table($table, $as = null)
    {
        $query = new Query\Builder($this, new Query\Processor());
        
        return $query->from($table);
    }
    
    /**
     * @inheritdoc
     */
    public function getSchemaBuilder()
    {
        return new Schema\Builder($this);
    }
    
    
    /**
     * @inheritdoc
     */
    public function disconnect()
    {
        unset($this->connection);
    }
    
    
    /**
     * @inheritdoc
     */
    public function getDriverName(): string
    {
        return 'elasticsearch';
    }
    
    /**
     * @inheritdoc
     */
    protected function getDefaultPostProcessor()
    {
        return new Query\Processor();
    }
    
    /**
     * @inheritdoc
     */
    protected function getDefaultQueryGrammar()
    {
        return new Query\Grammar();
    }
    
    /**
     * @inheritdoc
     */
    protected function getDefaultSchemaGrammar()
    {
        return new Schema\Grammar();
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }
    
    //----------------------------------------------------------------------
    // Connection Builder
    //----------------------------------------------------------------------
    
    protected function buildConnection(): Client
    {
        $type = $this->config['auth_type'] ?? null;
        $type = strtolower($type);
        
        if (!in_array($type, ['http', 'cloud'])) {
            throw new RuntimeException('Invalid [auth_type] in database config. Must be: http, cloud or api');
        }
        
        return $this->{'_'.$type.'Connection'}();
        
    }

    protected function _httpConnection(): Client
    {
        $hosts = $this->config['hosts'] ?? null;
        $username = $this->config['username'] ?? null;
        $pass = $this->config['password'] ?? null;
        $certPath = $this->config['ssl_cert'] ?? null;

        $cb = ClientBuilder::create()->setHosts($hosts);

        if ($this->config['curl_options'] ?? false) {
            $curlOptions = $this->config['curl_options'];

            if ($username && $pass) {
                $curlOptions['curl'] = ($curlOptions['curl'] ?? []) + [
                    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                    CURLOPT_USERPWD  => $username.':'.$pass
                ];
            }

            $cb->setConnectionParams([
                'client' => $curlOptions
            ]);
        } else {
            if ($username && $pass) {
                $cb->setBasicAuthentication($username, $pass)->build();
            }
            if ($certPath) {
                $cb->setSSLCert($certPath);
            } else {
                $verifySsl = $this->config['ssl_verify'] ?? true;
                $cb->setSSLVerification($verifySsl);
            }
        }
        return $cb->build();
    }
    
    protected function _cloudConnection(): Client
    {
        $cloudId = $this->config['cloud_id'] ?? null;
        $username = $this->config['username'] ?? null;
        $pass = $this->config['password'] ?? null;
        $apiId = $this->config['api_id'] ?? null;
        $apiKey = $this->config['api_key'] ?? null;
        $certPath = $this->config['ssl_cert'] ?? null;
        $cb = ClientBuilder::create()->setElasticCloudId($cloudId);
        
        if ($apiId && $apiKey) {
            $cb->setApiKey($apiKey, $apiId)->build();
        } elseif ($username && $pass) {
            $cb->setBasicAuthentication($username, $pass)->build();
        }
        if ($certPath) {
            $cb->setSSLVerification($certPath);
        }
        
        return $cb->build();
    }
    
    
    //----------------------------------------------------------------------
    // Dynamic call routing to DSL bridge
    //----------------------------------------------------------------------
    
    public function __call($method, $parameters)
    {
        if (!$this->index) {
            $this->index = $this->indexPrefix.'*';
        }
        
        $bridge = new Bridge($this->client, $this->index, $this->maxSize, $this->indexPrefix);
        
        return $bridge->{'process'.Str::studly($method)}(...$parameters);
    }
}
