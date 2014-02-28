<?php

class TwisterPost
{
    public $user = '';

    public $twisterPath = '';
    public $rpcuser = 'user';
    public $rpcpassword = 'pwd';
    public $rpcport = 28332;

    public $lastError = null;

    protected $maxId = -1;

    public function __construct($user)
    {
        $this->user = $user;
    }

    protected function getRpcCommand($method, $params = '')
    {
        $twisterd_path = $this->twisterPath . "twisterd";
        $twisterd_path .= " -rpcuser={$this->rpcuser}";
        $twisterd_path .= " -rpcpassword={$this->rpcpassword}";
        $twisterd_path .= " -rpcport={$this->rpcport}";
        $twisterd_path .= " $method";
        if (!empty($params)) {
            $twisterd_path .= " $params";
        }

        return $twisterd_path;
    }

    public function runRpcCommand($method, $params = '')
    {
        $cmd = $this->getRpcCommand($method, $params);

        $result = null;
        if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
            $this->exec_win($cmd, $result);
        } else {
            exec($cmd, $result);
        }
        $result = json_decode(implode(' ', $result));

        return $result;
    }

    public function updateMaxId()
    {
        $this->lastError = null;
        $this->maxId = 0;

        $result = $this->runRpcCommand('getposts', "1 '[{\"username\":\"{$this->user}\"}]'");
        if (isset($result->code) && $result->code < 0) {
            $this->maxId = -1;
            $this->lastError = $result;
            return false;
        }
        if (isset($result[0])) {
            if (isset($result[0]->userpost->k)) {
                $this->maxId = $result[0]->userpost->k;
            }
        }

        $result = $this->runRpcCommand('dhtget', "{$this->user} status s");
        if (isset($result->code) && $result->code < 0) {
            $this->maxId = -1;
            $this->lastError = $result;
            return false;
        }
        if (isset($result[0]) && isset($result[0]->p)) {
            if (isset($result[0]->p->seq)) {
                $this->maxId = max($this->maxId, $result[0]->p->seq);
            }
            if (isset($result[0]->p->v) && isset($result[0]->p->v->userpost) && isset($result[0]->p->v->userpost->k)) {
                $this->maxId = max($this->maxId, $result[0]->p->v->userpost->k);
            }
        }

        return true;
    }

    public function postMessage($text)
    {
        $this->lastError = null;

        if ($this->maxId < 0) {
            if (!$this->updateMaxId()) {
                return false;
            }
        }

        $k = $this->maxId + 1;
        $text = '"' . str_replace('"', '\\"', $text) . '"';
        $result = $this->runRpcCommand('newpostmsg', "{$this->user} $k $text");
        if (isset($result->code) && $result->code < 0) {
            $this->maxId = -1;
            $this->lastError = $result;
            return false;
        }
        $this->maxId = $k;

        return true;
    }

    public function prettyPrint($title, $url = '', $tags = null, $maxLen = 140)
    {
        $title_len = mb_strlen($title);
        $url_len  = mb_strlen($url);

        if ($url_len === 0) {
            if ($title_len > $maxLen) {
                $text = rtrim(mb_substr($title, 0, $maxLen - 1), ' ') . '…';
            } else {
                $text = $title;
            }
        } else if ($title_len + 1 + $url_len > $maxLen) {
            $text = rtrim(mb_substr($title, 0, $maxLen - 2 - $url_len), ' ') . '… ' . $url;
        } else {
            $text = $title . ' ' . $url;
        }

        if (isset($tags)) {
            foreach ($tags as $tag) {
                $text .= ' #' . str_replace(' ', '_', (string)$tag);
            }
            $text = mb_substr($text, 0, $maxLen + 1);
            $pos  = mb_strrpos($text, ' ');
            $text = mb_substr($text, 0, $pos);
        }

        return $text;
    }

    protected function exec_win($cmd, &$output = null, &$return_var = null)
    {
        $tempfile = '_php_exec.bat';

        $bat = "@echo off\r\n"
            . "@chcp 65001 >nul\r\n"
            . "@cd \"" . getcwd() . "\"\r\n"
            . "$cmd\r\n";

        file_put_contents($tempfile, $bat);
        exec("start /b cmd /c $tempfile", $output, $return_var);
        unlink($tempfile);

        return count($output) ? $output[count($output) - 1] : '';
    }
}
