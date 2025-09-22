<?php

class WasiSnapshotPreview1 {
    public $fd_table;

    public function __construct() {
        $this->fd_table = [
            STDIN,
            STDOUT,
            STDERR
        ];
    }

    public function fdWrite($store, $args) {
        list($fd, $iovs, $iovs_len, $rp) = $args;
        $file = $this->fd_table[$fd];
        $memory = $store->memories[0];
        $nwritten = 0;

        for ($i = 0; $i < $iovs_len; $i++) {
            $start = $this->unpackLeInt(substr($memory->data, $iovs, 4));
            $iovs += 4;
            $slen = $this->unpackLeInt(substr($memory->data, $iovs, 4));
            $iovs += 4;

            $data_to_write = substr($memory->data, $start, $slen);
            $nwritten += fwrite($file, $data_to_write);
        }

        $packed_nwritten = pack('V', $nwritten);
        for ($i = 0; $i < 4; $i++) {
            $memory->data[$rp + $i] = $packed_nwritten[$i];
        }

        return 0;
    }

    public function toModule() {
        return [
            'fd_write' => function($store, $args) {
                return $this->fdWrite($store, $args);
            }
        ];
    }

    private function unpackLeInt($buf) {
        $result = unpack('V', $buf);
        return $result[1];
    }
}