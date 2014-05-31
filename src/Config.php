<?php

namespace Sokil\Mongo\Migrator;

use Symfony\Component\Yaml\Yaml;

class Config
{
    private $_config;
    
    private $_configFilePath;

    public function __construct($path)
    {
        $this->_configFilePath = rtrim($path, '/');
        
        $this->_config = Yaml::parse($path);
    }
    
    public function __get($name)
    {
        return isset($this->_config[$name]) ? $this->_config[$name] : null;
    }
    
    public function get($name)
    {
        if(false === strpos($name, '.')) {
            return isset($this->_config[$name]) ? $this->_config[$name] : null;
        }

        $value = $this->_config;
        foreach(explode('.', $name) as $field)
        {
            if(!isset($value[$field])) {
                return null;
            }

            $value = $value[$field];
        }

        return $value;
    }
    
    public function getMigrationsDir()
    {
        return dirname($this->_configFilePath) . '/' . trim($this->_config['path']['migrations'], '/');
    }
}