<?php

require_once 'phpconvm/const.php';
require_once 'phpconvm/leb128.php';
require_once 'phpconvm/instruction.php';
require_once 'phpconvm/wasi.php';
require_once 'phpconvm/binary_loader.php';
require_once 'phpconvm/store.php';
require_once 'phpconvm/runtime.php';

class PhpConVm {
    public const string VERSION = "0.1.2";

    public static function new($path = null, $buffer = null, ...$options) {
        if ($path !== null) {
            $buffer = fopen($path, 'rb');
        }
        if ($buffer === null) {
            throw new InvalidArgumentException("null buffer passed");
        }
        return BinaryLoader::loadFromBuffer($buffer, ...$options);
    }
}

class Section {
    public $name;
    public $code;
    public $size;
}

class TypeSection extends Section {
    public $defined_types = [];
    public $defined_results = [];

    public function __construct() {
        $this->name = "Type";
        $this->code = 0x1;
    }
}

class FunctionSection extends Section {
    public $func_indices = [];

    public function __construct() {
        $this->name = "Function";
        $this->code = 0x3;
    }
}

class MemorySection extends Section {
    public $limits = [];

    public function __construct() {
        $this->name = "Memory";
        $this->code = 0x5;
    }
}

class CodeSection extends Section {
    public $func_codes = [];

    public function __construct() {
        $this->name = "Code";
        $this->code = 0xa;
    }
}

class CodeBody {
    public $locals_count = [];
    public $locals_type = [];
    public $body = [];

    public function __construct($callback = null) {
        if ($callback) {
            call_user_func($callback, $this);
        }
    }
}

class DataSection extends Section {
    public $segments = [];

    public function __construct() {
        $this->name = "Data";
        $this->code = 0xb;
    }
}

class Segment {
    public $flags;
    public $offset;
    public $data;

    public function __construct($callback = null) {
        if ($callback) {
            call_user_func($callback, $this);
        }
    }
}

class ExportSection extends Section {
    public $exports = [];

    public function __construct() {
        $this->name = "Export";
        $this->code = 0x7;
    }

    public function addDesc($callback) {
        $desc = new ExportDesc();
        call_user_func($callback, $desc);
        $this->exports[$desc->name] = $desc;
    }
}

class ExportDesc {
    public $name;
    public $kind;
    public $func_index;
}

class ImportSection extends Section {
    public $imports = [];

    public function __construct() {
        $this->name = "Import";
        $this->code = 0x2;
    }

    public function addDesc($callback = null) {
        $desc = new ImportDesc();
        if ($callback) {
            call_user_func($callback, $desc);
        }
        $this->imports[] = $desc;
    }
}

class ImportDesc {
    public $module_name;
    public $name;
    public $kind;
    public $sig_index;
}

class Instance {
    public $version;
    public $sections = [];
    public $runtime;
    public $store;
    public $exports;
    private $import_object;

    public function __construct($import_object, $callback = null) {
        if ($callback) {
            call_user_func($callback, $this);
        }
        $this->import_object = $import_object;

        $this->store = new Store($this);
        $this->exports = new Exports($this->exportSection(), $this->store);
        $this->runtime = new Runtime($this);
    }

    public function importSection() {
        foreach ($this->sections as $section) {
            if ($section->code === WasmConst::SectionImport) {
                return $section;
            }
        }
        return new ImportSection();
    }

    public function typeSection() {
        foreach ($this->sections as $section) {
            if ($section->code === WasmConst::SectionType) {
                return $section;
            }
        }
        return null;
    }

    public function memorySection() {
        foreach ($this->sections as $section) {
            if ($section->code === WasmConst::SectionMemory) {
                return $section;
            }
        }
        return null;
    }

    public function dataSection() {
        foreach ($this->sections as $section) {
            if ($section->code === WasmConst::SectionData) {
                return $section;
            }
        }
        return null;
    }

    public function functionSection() {
        foreach ($this->sections as $section) {
            if ($section->code === WasmConst::SectionFunction) {
                return $section;
            }
        }
        return null;
    }

    public function codeSection() {
        foreach ($this->sections as $section) {
            if ($section->code === WasmConst::SectionCode) {
                return $section;
            }
        }
        return null;
    }

    public function exportSection() {
        foreach ($this->sections as $section) {
            if ($section->code === WasmConst::SectionExport) {
                return $section;
            }
        }
        return new ExportSection();
    }

    public function getImportObject() {
        return $this->import_object;
    }
}

class PhpConVmException extends Exception {}
class LoadError extends PhpConVmException {}
class ArgumentError extends PhpConVmException {}
class EvalError extends PhpConVmException {}