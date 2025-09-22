<?php

require_once 'phpconvm.php';

class VM {
    public $instance;

    public function __construct($wasm_path = null) {
        echo "[debug] VM initialized.\n";
        
        if ($wasm_path) {
            $this->instance = PhpConVm::new($wasm_path);
        }
    }

    public function loadWasm($path) {
        $this->instance = PhpConVm::new($path);
        return $this;
    }

    public function call($function_name, ...$args) {
        if (!$this->instance) {
            throw new Exception("No WASM module loaded");
        }
        
        return $this->instance->runtime->call($function_name, $args);
    }

    public function __call($name, $arguments) {
        if ($this->instance && $this->instance->runtime->callable($name)) {
            return $this->instance->runtime->call($name, $arguments);
        }
        
        throw new BadMethodCallException("Method $name not found");
    }
}
