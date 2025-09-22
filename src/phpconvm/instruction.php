<?php

class Op {
    public $code;
    public $operand;

    public function __construct($code, $operand) {
        $this->code = $code;
        $this->operand = $operand;
    }

    public static function toSym($chr) {
        $ord = ord($chr);
        switch ($ord) {
            case 0x04: return 'if';
            case 0x0b: return 'end';
            case 0x0f: return 'return';
            case 0x10: return 'call';
            case 0x20: return 'local_get';
            case 0x21: return 'local_set';
            case 0x36: return 'i32_store';
            case 0x41: return 'i32_const';
            case 0x48: return 'i32_lts';
            case 0x4d: return 'i32_leu';
            case 0x6a: return 'i32_add';
            case 0x6b: return 'i32_sub';
            default:
                throw new Exception("unimplemented: " . sprintf("%04x", $ord));
        }
    }

    public static function operandOf($code) {
        switch ($code) {
            case 'local_get':
            case 'local_set':
            case 'call':
                return ['u32'];
            case 'i32_const':
                return ['i32'];
            case 'i32_store':
                return ['u32', 'u32'];
            case 'if':
                return ['u8_block'];
            default:
                return [];
        }
    }

    public static function i2type($code) {
        switch ($code) {
            case 0x7f: return 'i32';
            case 0x7e: return 'i64';
            case 0x7d: return 'f32';
            case 0x7c: return 'f64';
            default:
                throw new Exception("unknown type code $code");
        }
    }
}

class Block {
    const VOID = null;
    public $block_types;

    public static function void() {
        return new self(self::VOID);
    }

    public function __construct($block_types = self::VOID) {
        $this->block_types = $block_types;
    }

    public function isVoid() {
        return $this->block_types === null;
    }

    public function resultSize() {
        if ($this->block_types !== null) {
            return count($this->block_types);
        } else {
            return 0;
        }
    }
}