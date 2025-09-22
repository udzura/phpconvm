<?php

class Runtime {
    public $stack = [];
    public $call_stack = [];
    private $instance;

    public function __construct($instance) {
        $this->instance = $instance;
    }

    public function callable($name) {
        return isset($this->instance->exports->mappings[$name]);
    }

    public function call($name, $args) {
        if (!$this->callable($name)) {
            throw new Exception("function $name not found");
        }

        list($kind, $fn) = $this->instance->exports->mappings[$name];
        if ($kind !== 0) {
            throw new Exception("$name is not a function");
        }

        if (count($fn->callsig) !== count($args)) {
            throw new ArgumentError("unmatch arg size");
        }

        foreach ($args as $arg) {
            $this->stack[] = $arg;
        }

        if ($fn instanceof WasmFunction) {
            return $this->invokeInternal($fn);
        } elseif ($fn instanceof ExternalFunction) {
            return $this->invokeExternal($fn);
        } else {
            throw new Exception("registered pointer is not to a function");
        }
    }

    private function invokeInternal($wasm_function) {
        $arity = count($wasm_function->retsig);
        $this->pushFrame($wasm_function);
        $this->execute();

        if ($arity > 0) {
            if ($arity > 1) {
                throw new Exception("return arity >= 2 not yet supported");
            }
            if (empty($this->stack)) {
                throw new Exception("stack empty");
            }
            return array_pop($this->stack);
        }

        return null;
    }

    private function invokeExternal($external_function) {
        $local_start = count($this->stack) - count($external_function->callsig);
        $args = array_slice($this->stack, $local_start);
        $this->stack = array_slice($this->stack, 0, $local_start);

        return call_user_func($external_function->callable, $this->instance->store, $args);
    }

    // 他のメソッドも同様に実装...

    public function __call($name, $args) {
        if ($this->callable($name)) {
            return $this->call($name, $args);
        }
        throw new BadMethodCallException("Method $name not found");
    }
}

class Frame {
    public $pc;
    public $sp;
    public $body;
    public $arity;
    public $labels = [];
    public $locals;

    public function __construct($pc, $sp, $body, $arity, $locals) {
        $this->pc = $pc;
        $this->sp = $sp;
        $this->body = $body;
        $this->arity = $arity;
        $this->locals = $locals;
    }
}

class Label {
    public $kind;
    public $pc;
    public $sp;
    public $arity;

    public function __construct($kind, $pc, $sp, $arity) {
        $this->kind = $kind;
        $this->pc = $pc;
        $this->sp = $sp;
        $this->arity = $arity;
    }
}