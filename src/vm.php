<?php
class VM {
    public function __construct() {
        echo "VM initialized.\n";
        $this->hoge = "default\n";
        echo $this->hoge;
    }
    public $hoge;
}
