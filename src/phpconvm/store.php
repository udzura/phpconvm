<?php

class Store {
    public $funcs = [];
    public $memories = [];

    public function __construct($instance) {
        $type_section = $instance->typeSection();
        $func_section = $instance->functionSection();
        $code_section = $instance->codeSection();
        $import_section = $instance->importSection();

        if ($type_section && $func_section && $code_section) {
            // Import functions
            foreach ($import_section->imports as $desc) {
                $callsig = $type_section->defined_types[$desc->sig_index];
                $retsig = $type_section->defined_results[$desc->sig_index];
                $import_object = $instance->getImportObject();
                
                if (!isset($import_object[$desc->module_name])) {
                    throw new Exception("module {$desc->module_name} not found");
                }
                
                $imported_module = $import_object[$desc->module_name];
                if (!isset($imported_module[$desc->name])) {
                    throw new Exception("function {$desc->module_name}.{$desc->name} not found");
                }

                $ext_function = new ExternalFunction($callsig, $retsig, $imported_module[$desc->name]);
                $this->funcs[] = $ext_function;
            }

            // WASM functions
            foreach ($func_section->func_indices as $findex => $sigindex) {
                $callsig = $type_section->defined_types[$sigindex];
                $retsig = $type_section->defined_results[$sigindex];
                $codes = $code_section->func_codes[$findex];
                $wasm_function = new WasmFunction($callsig, $retsig, $codes);
                $this->funcs[] = $wasm_function;
            }
        }

        // Initialize memories
        $memory_section = $instance->memorySection();
        if ($memory_section) {
            foreach ($memory_section->limits as list($min, $max)) {
                $this->memories[] = new Memory($min, $max);
            }

            // Initialize data
            $data_section = $instance->dataSection();
            if ($data_section) {
                foreach ($data_section->segments as $segment) {
                    $memory = $this->memories[$segment->flags];
                    if (!$memory) {
                        throw new Exception("invalid memory index: {$segment->flags}");
                    }

                    $data_start = $segment->offset;
                    $data_end = $segment->offset + strlen($segment->data);
                    
                    if ($data_end > strlen($memory->data)) {
                        throw new Exception("data too large for memory");
                    }

                    for ($i = 0; $i < strlen($segment->data); $i++) {
                        $memory->data[$data_start + $i] = $segment->data[$i];
                    }
                }
            }
        }
    }

    public function offsetGet($idx) {
        return $this->funcs[$idx] ?? null;
    }
}

class Memory {
    public $data;
    public $max;

    public function __construct($min, $max) {
        $this->data = str_repeat("\0", $min * 64 * 1024);
        $this->max = $max;
    }
}

class WasmFunction {
    public $callsig;
    public $retsig;
    public $code_body;

    public function __construct($callsig, $retsig, $code_body) {
        $this->callsig = $callsig;
        $this->retsig = $retsig;
        $this->code_body = $code_body;
    }

    public function body() {
        return $this->code_body->body;
    }

    public function localsType() {
        return $this->code_body->locals_type;
    }

    public function localsCount() {
        return $this->code_body->locals_count;
    }
}

class ExternalFunction {
    public $callsig;
    public $retsig;
    public $callable;

    public function __construct($callsig, $retsig, $callable) {
        $this->callsig = $callsig;
        $this->retsig = $retsig;
        $this->callable = $callable;
    }
}

class Exports {
    public $mappings = [];

    public function __construct($export_section, $store) {
        foreach ($export_section->exports as $name => $desc) {
            $this->mappings[$name] = [$desc->kind, $store->funcs[$desc->func_index]];
        }
    }
}