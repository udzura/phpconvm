<?php

class BinaryLoader {
    use Leb128Helpers;

    private static $buf;

    public static function loadFromBuffer($buf, $import_object = [], $enable_wasi = true) {
        self::$buf = $buf;

        $version = self::preamble();
        $sections = self::sections();

        if ($enable_wasi) {
            $wasi_env = new WasiSnapshotPreview1();
            $import_object['wasi_snapshot_preview1'] = $wasi_env->toModule();
        }

        return new Instance($import_object, function($i) use ($version, $sections) {
            $i->version = $version;
            $i->sections = $sections;
        });
    }

    public static function preamble() {
        $asm = fread(self::$buf, 4);
        if ($asm !== "\x00asm") {
            throw new LoadError("invalid preamble");
        }

        $vstr = fread(self::$buf, 4);
        if (strlen($vstr) !== 4) {
            throw new LoadError("buffer too short");
        }

        $version = 0;
        for ($i = 0; $i < 4; $i++) {
            $version |= (ord($vstr[$i]) << ($i * 8));
        }

        if ($version !== 1) {
            throw new LoadError("unsupported version: $version");
        }

        return $version;
    }

    public static function sections() {
        $sections = [];

        while (($byte = fread(self::$buf, 1)) !== '') {
            $code = ord($byte);

            switch ($code) {
                case WasmConst::SectionType:
                    $section = self::typeSection();
                    break;
                case WasmConst::SectionImport:
                    $section = self::importSection();
                    break;
                case WasmConst::SectionFunction:
                    $section = self::functionSection();
                    break;
                case WasmConst::SectionTable:
                    $section = self::unimplementedSkipSection($code);
                    break;
                case WasmConst::SectionMemory:
                    $section = self::memorySection();
                    break;
                case WasmConst::SectionGlobal:
                    $section = self::unimplementedSkipSection($code);
                    break;
                case WasmConst::SectionExport:
                    $section = self::exportSection();
                    break;
                case WasmConst::SectionStart:
                    $section = self::unimplementedSkipSection($code);
                    break;
                case WasmConst::SectionElement:
                    $section = self::unimplementedSkipSection($code);
                    break;
                case WasmConst::SectionCode:
                    $section = self::codeSection();
                    break;
                case WasmConst::SectionData:
                    $section = self::dataSection();
                    break;
                case WasmConst::SectionCustom:
                    $section = self::unimplementedSkipSection($code);
                    break;
                default:
                    throw new LoadError("unknown code: $code(" . dechex($code) . ")");
            }

            if ($section) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    private static function typeSection() {
        $dest = new TypeSection();
        $size = (new self())->fetchUleb128(self::$buf);
        $dest->size = $size;
        
        $sbuf = fopen('data://text/plain,' . fread(self::$buf, $size), 'rb');

        $len = (new self())->fetchUleb128($sbuf);
        for ($i = 0; $i < $len; $i++) {
            $fncode = fread($sbuf, 1);
            if ($fncode !== "\x60") {
                throw new LoadError("not a function definition");
            }

            $arglen = (new self())->fetchUleb128($sbuf);
            $arg = [];
            for ($j = 0; $j < $arglen; $j++) {
                $ty = ord(fread($sbuf, 1));
                switch ($ty) {
                    case 0x7f: $arg[] = 'i32'; break;
                    case 0x7e: $arg[] = 'i64'; break;
                    default:
                        throw new Exception("unsupported for now: $ty");
                }
            }
            $dest->defined_types[] = $arg;

            $retlen = (new self())->fetchUleb128($sbuf);
            $ret = [];
            for ($j = 0; $j < $retlen; $j++) {
                $ty = ord(fread($sbuf, 1));
                switch ($ty) {
                    case 0x7f: $ret[] = 'i32'; break;
                    case 0x7e: $ret[] = 'i64'; break;
                    default:
                        throw new Exception("unsupported for now: $ty");
                }
            }
            $dest->defined_results[] = $ret;
        }

        fclose($sbuf);
        return $dest;
    }

    private static function importSection() {
        $dest = new ImportSection();
        $size = (new self())->fetchUleb128(self::$buf);
        $dest->size = $size;
        $sbuf = fopen('data://text/plain,' . fread(self::$buf, $size), 'rb');

        $len = (new self())->fetchUleb128($sbuf);
        for ($i = 0; $i < $len; $i++) {
            $mlen = (new self())->fetchUleb128($sbuf);
            $module_name = fread($sbuf, $mlen);
            $nlen = (new self())->fetchUleb128($sbuf);
            $name = fread($sbuf, $nlen);
            $kind_ = fread($sbuf, 1);
            $kind = ord($kind_);

            $index = (new self())->fetchUleb128($sbuf);
            $dest->addDesc(function($desc) use ($module_name, $name, $kind, $index) {
                $desc->module_name = $module_name;
                $desc->name = $name;
                $desc->kind = $kind;
                $desc->sig_index = $index;
            });
        }

        fclose($sbuf);
        return $dest;
    }

    private static function memorySection() {
        $dest = new MemorySection();
        $size = (new self())->fetchUleb128(self::$buf);
        $dest->size = $size;
        $sbuf = fopen('data://text/plain,' . fread(self::$buf, $size), 'rb');

        $len = (new self())->fetchUleb128($sbuf);
        if ($len !== 1) {
            throw new LoadError("memory section has invalid size: $len");
        }
        for ($i = 0; $i < $len; $i++) {
            $flags = (new self())->fetchUleb128($sbuf);
            $min = (new self())->fetchUleb128($sbuf);

            $max = null;
            if ($flags !== 0) {
                $max = (new self())->fetchUleb128($sbuf);
            }
            $dest->limits[] = [$min, $max];
        }
        
        fclose($sbuf);
        return $dest;
    }

    private static function functionSection() {
        $dest = new FunctionSection();
        $size = (new self())->fetchUleb128(self::$buf);
        $dest->size = $size;
        $sbuf = fopen('data://text/plain,' . fread(self::$buf, $size), 'rb');

        $len = (new self())->fetchUleb128($sbuf);
        for ($i = 0; $i < $len; $i++) {
            $index = (new self())->fetchUleb128($sbuf);
            $dest->func_indices[] = $index;
        }
        
        fclose($sbuf);
        return $dest;
    }

    private static function codeSection() {
        $dest = new CodeSection();
        $size = (new self())->fetchUleb128(self::$buf);
        $dest->size = $size;
        $sbuf = fopen('data://text/plain,' . fread(self::$buf, $size), 'rb');

        $len = (new self())->fetchUleb128($sbuf);
        for ($i = 0; $i < $len; $i++) {
            $ilen = (new self())->fetchUleb128($sbuf);
            $code = fread($sbuf, $ilen);
            $last_code = substr($code, -1);
            if (ord($last_code) !== 0x0b) {
                error_log("warning: instruction not ended with inst end(0x0b): 0x" . dechex(ord($last_code)));
            }
            
            $cbuf = fopen('data://text/plain,' . $code, 'rb');
            $locals_count = [];
            $locals_type = [];
            $locals_len = (new self())->fetchUleb128($cbuf);
            for ($j = 0; $j < $locals_len; $j++) {
                $type_count = (new self())->fetchUleb128($cbuf);
                $locals_count[] = $type_count;
                $value_type = ord(fread($cbuf, 1));
                $locals_type[] = Op::i2type($value_type);
            }
            $body = self::codeBody($cbuf);
            $dest->func_codes[] = new CodeBody(function($b) use ($locals_count, $locals_type, $body) {
                $b->locals_count = $locals_count;
                $b->locals_type = $locals_type;
                $b->body = $body;
            });
            fclose($cbuf);
        }
        
        fclose($sbuf);
        return $dest;
    }

    private static function codeBody($buf) {
        $dest = [];
        while (($c = fread($buf, 1)) !== '') {
            $code = Op::toSym($c);
            $operand_types = Op::operandOf($code);
            $operand = [];
            foreach ($operand_types as $typ) {
                switch ($typ) {
                    case 'u32':
                        $operand[] = (new self())->fetchUleb128($buf);
                        break;
                    case 'i32':
                        $operand[] = (new self())->fetchSleb128($buf);
                        break;
                    case 'u8_block': // :if specific
                        $block_ope = fread($buf, 1);
                        if (!$block_ope) {
                            throw new Exception("buffer too short for if");
                        }
                        if (ord($block_ope) === 0x40) {
                            $operand[] = Block::void();
                        } else {
                            $operand[] = new Block([ord($block_ope)]);
                        }
                        break;
                    default:
                        error_log("warning: unknown type " . $typ . ". defaulting to u32");
                        $operand[] = (new self())->fetchUleb128($buf);
                        break;
                }
            }

            $dest[] = new Op($code, $operand);
        }

        return $dest;
    }

    private static function dataSection() {
        $dest = new DataSection();
        $size = (new self())->fetchUleb128(self::$buf);
        $dest->size = $size;
        $sbuf = fopen('data://text/plain,' . fread(self::$buf, $size), 'rb');

        $len = (new self())->fetchUleb128($sbuf);
        for ($i = 0; $i < $len; $i++) {
            $mem_index = (new self())->fetchUleb128($sbuf);
            $code = self::fetchInsnWhileEnd($sbuf);
            $ops = self::codeBody(fopen('data://text/plain,' . $code, 'rb'));
            $offset = self::decodeExpr($ops);

            $len = (new self())->fetchUleb128($sbuf);
            $data = fread($sbuf, $len);
            if (!$data) {
                throw new LoadError("buffer too short");
            }

            $segment = new Segment(function($seg) use ($mem_index, $offset, $data) {
                $seg->flags = $mem_index;
                $seg->offset = $offset;
                $seg->data = $data;
            });
            $dest->segments[] = $segment;
        }
        
        fclose($sbuf);
        return $dest;
    }

    private static function fetchInsnWhileEnd($sbuf) {
        $code = "";
        while (($c = fread($sbuf, 1)) !== '') {
            $code .= $c;
            if ($c === "\x0b") { // :end
                break;
            }
        }
        return $code;
    }

    private static function decodeExpr($ops) {
        // sees first opcode
        $op = $ops[0] ?? null;
        if (!$op) {
            throw new LoadError("empty opcodes");
        }
        switch ($op->code) {
            case 'i32_const':
                $arg = $op->operand[0];
                if (!is_int($arg)) {
                    throw new Exception("Invalid definition of operand");
                }
                return $arg;
            default:
                throw new Exception("Unimplemented offset op: " . $op->code);
        }
    }

    private static function exportSection() {
        $dest = new ExportSection();
        $size = (new self())->fetchUleb128(self::$buf);
        $dest->size = $size;
        $sbuf = fopen('data://text/plain,' . fread(self::$buf, $size), 'rb');

        $len = (new self())->fetchUleb128($sbuf);
        for ($i = 0; $i < $len; $i++) {
            $nlen = (new self())->fetchUleb128($sbuf);
            $name = fread($sbuf, $nlen);
            $kind_ = fread($sbuf, 1);
            $kind = ord($kind_);

            $index = (new self())->fetchUleb128($sbuf);
            $dest->addDesc(function($desc) use ($name, $kind, $index) {
                $desc->name = $name;
                $desc->kind = $kind;
                $desc->func_index = $index;
            });
        }

        fclose($sbuf);
        return $dest;
    }

    private static function unimplementedSkipSection($code) {
        error_log("warning: unimplemented section: 0x" . dechex($code));
        $size = (new self())->fetchUleb128(self::$buf);
        fread(self::$buf, $size);
        return null;
    }
}