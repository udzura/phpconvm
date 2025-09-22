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

    public function callIndex($idx, $args) {
        $fn = $this->instance->store->funcs[$idx] ?? null;
        if (!$fn) {
            throw new Exception("func $idx not found");
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

    private function pushFrame($wasm_function) {
        $local_start = count($this->stack) - count($wasm_function->callsig);
        $locals = array_slice($this->stack, $local_start);
        if ($locals === false) {
            throw new LoadError("stack too short");
        }
        $this->stack = $this->drainedStack($local_start);

        foreach ($wasm_function->localsType() as $typ) {
            switch ($typ) {
                case 'i32':
                case 'u32':
                    $locals[] = 0;
                    break;
                default:
                    error_log("warning: unknown type $typ. default to Object");
                    $locals[] = new stdClass();
                    break;
            }
        }

        $arity = count($wasm_function->retsig);
        $frame = new Frame(-1, count($this->stack), $wasm_function->body(), $arity, $locals);
        $this->call_stack[] = $frame;
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

    private function execute() {
        while (true) {
            $cur_frame = end($this->call_stack);
            if (!$cur_frame) {
                break;
            }
            $cur_frame->pc += 1;
            $insn = $cur_frame->body[$cur_frame->pc] ?? null;
            if (!$insn) {
                break;
            }
            $this->evalInsn($cur_frame, $insn);
        }
    }

    private function evalInsn($frame, $insn) {
        switch ($insn->code) {
            case 'if':
                $block = $insn->operand[0];
                if (!($block instanceof Block)) {
                    throw new EvalError("if op without block");
                }
                $cond = array_pop($this->stack);
                if (!is_int($cond)) {
                    throw new EvalError("cond not found");
                }
                $next_pc = $this->fetchOpsWhileEnd($frame->body, $frame->pc);
                if ($cond === 0) {
                    $frame->pc = $next_pc;
                }
                
                $label = new Label('if', $next_pc, count($this->stack), $block->resultSize());
                $frame->labels[] = $label;
                break;

            case 'local_get':
                $idx = $insn->operand[0];
                if (!is_int($idx)) {
                    throw new EvalError("[BUG] invalid type of operand");
                }
                $local = $frame->locals[$idx] ?? null;
                if ($local === null) {
                    throw new EvalError("local not found");
                }
                $this->stack[] = $local;
                break;

            case 'local_set':
                $idx = $insn->operand[0];
                if (!is_int($idx)) {
                    throw new EvalError("[BUG] invalid type of operand");
                }
                $value = array_pop($this->stack);
                if ($value === null) {
                    throw new EvalError("value should be pushed");
                }
                $frame->locals[$idx] = $value;
                break;

            case 'i32_lts':
                $right = array_pop($this->stack);
                $left = array_pop($this->stack);
                if (!is_int($right) || !is_int($left)) {
                    throw new EvalError("maybe empty stack");
                }
                $value = ($left < $right) ? 1 : 0;
                $this->stack[] = $value;
                break;

            case 'i32_leu':
                $right = array_pop($this->stack);
                $left = array_pop($this->stack);
                if (!is_int($right) || !is_int($left)) {
                    throw new EvalError("maybe empty stack");
                }
                $value = ($left >= $right) ? 1 : 0;
                $this->stack[] = $value;
                break;

            case 'i32_add':
                $right = array_pop($this->stack);
                $left = array_pop($this->stack);
                if (!is_int($right) || !is_int($left)) {
                    throw new EvalError("maybe empty stack");
                }
                $this->stack[] = $left + $right;
                break;

            case 'i32_sub':
                $right = array_pop($this->stack);
                $left = array_pop($this->stack);
                if (!is_int($right) || !is_int($left)) {
                    throw new EvalError("maybe empty stack");
                }
                $this->stack[] = $left - $right;
                break;

            case 'i32_const':
                $const = $insn->operand[0];
                if (!is_int($const)) {
                    throw new EvalError("[BUG] invalid type of operand");
                }
                $this->stack[] = $const;
                break;

            case 'i32_store':
                $align = $insn->operand[0]; // TODO: alignment support?
                $offset = $insn->operand[1];
                if (!is_int($offset)) {
                    throw new EvalError("[BUG] invalid type of operand");
                }

                $value = array_pop($this->stack);
                $addr = array_pop($this->stack);
                if (!is_int($value) || !is_int($addr)) {
                    throw new EvalError("maybe stack too short");
                }

                $at = $addr + $offset;
                $data_end = $at + 4; // sizeof(i32)
                $memory = $this->instance->store->memories[0] ?? null;
                if (!$memory) {
                    throw new Exception("[BUG] no memory");
                }
                
                $packed = pack('V', $value); // little-endian i32
                for ($i = 0; $i < 4; $i++) {
                    $memory->data[$at + $i] = $packed[$i];
                }
                break;

            case 'call':
                $idx = $insn->operand[0];
                if (!is_int($idx)) {
                    throw new EvalError("[BUG] local operand not found");
                }
                $fn = $this->instance->store->funcs[$idx] ?? null;
                if ($fn instanceof WasmFunction) {
                    $this->pushFrame($fn);
                } elseif ($fn instanceof ExternalFunction) {
                    $ret = $this->invokeExternal($fn);
                    if ($ret !== null) {
                        $this->stack[] = $ret;
                    }
                } else {
                    throw new Exception("got a non-function pointer");
                }
                break;

            case 'return':
                $old_frame = array_pop($this->call_stack);
                if (!$old_frame) {
                    throw new EvalError("maybe empty call stack");
                }
                $this->stackUnwind($old_frame->sp, $old_frame->arity);
                break;

            case 'end':
                if ($old_label = array_pop($frame->labels)) {
                    $frame->pc = $old_label->pc;
                    $this->stackUnwind($old_label->sp, $old_label->arity);
                } else {
                    $old_frame = array_pop($this->call_stack);
                    if (!$old_frame) {
                        throw new EvalError("maybe empty call stack");
                    }
                    $this->stackUnwind($old_frame->sp, $old_frame->arity);
                }
                break;

            default:
                throw new EvalError("unimplemented instruction: " . $insn->code);
        }
    }

    private function fetchOpsWhileEnd($ops, $pc_start) {
        $cursor = $pc_start;
        $depth = 0;
        
        while (true) {
            $cursor += 1;
            $inst = $ops[$cursor] ?? null;
            if (!$inst) {
                throw new EvalError("end op not found");
            }
            
            switch ($inst->code) {
                case 'if':
                    $depth += 1;
                    break;
                case 'end':
                    if ($depth === 0) {
                        return $cursor;
                    } else {
                        $depth -= 1;
                    }
                    break;
            }
        }

        // throw new Exception("[BUG] unreachable");
    }

    private function stackUnwind($sp, $arity) {
        if ($arity > 0) {
            if ($arity > 1) {
                throw new Exception("return arity >= 2 not yet supported");
            }
            $value = array_pop($this->stack);
            if ($value === null) {
                throw new EvalError("cannot obtain return value");
            }
            $this->stack = $this->drainedStack($sp);
            $this->stack[] = $value;
        } else {
            $this->stack = $this->drainedStack($sp);
        }
    }

    private function drainedStack($finish) {
        $drained = array_slice($this->stack, 0, $finish);
        if ($drained === false) {
            error_log("warning: state of stack: " . print_r($this->stack, true));
            throw new EvalError("stack too short");
        }
        return $drained;
    }

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