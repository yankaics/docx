<?php

/*
 * This file is part of Docx.
 *
 * Copyright (c) 2014 MIT License
 */

namespace Docx\Web;

use Docx\Common;


/**
 * 路由器
 * 简化自James Cleveland的Ham <https://github.com/radiosilence/Ham>.
 *
 * @author Ryan Liu <azhai@126.com>
 *
 * //EXAMPLE:
 * $root = Router::getCurrent(); //根路由
 * $root->route('/', function() {
 *     echo "Hello World!\n";
 * });
 * $root->expose(__DIR__, '*.php');
 * $root->expose(__DIR__, '*' . '/' . '*.php');
 */
class Router
{
    public static $aliases = [
        '<int>' => '([0-9\-]+)',
        '<float>' => '([0-9\.\-]+)',
        '<num>' => '([0-9\.\-,]*)',
        '<string>' => '([a-z0-9\-_]+)',
        '<page>' => '([0-9]*)/?([0-9]*)/?',
        '<path>' => '([a-z0-9\-_/]*)',
        '<word>' => '([^/]*)',
    ];
    public $rule = '';
    public $path = '';
    public $url = '';
    public $args = [];
    public $handlers = [];
    protected static $current = null;
    protected $filename = '';
    protected $prefix = '';
    protected $children = []; // 子路由器文件名（相对路径）
    protected $items = []; // 路由

    /**
     * 构造函数，加载一组路由.
     *
     * @param string $filename
     *                         路由所属文件名（绝对路径）
     */
    protected function __construct($filename = '', $prefix = '/')
    {
        $seps = ['//', '/', '\\'];
        $this->filename = str_replace($seps, DIRECTORY_SEPARATOR, $filename);
        $this->prefix = rtrim($prefix, '/');
        self::$current = $this; //必须在读取文件之前
        if ($this->filename && is_readable($this->filename)) {
            include_once $this->filename;
        }
    }

    public static function getCurrent()
    {
        return self::$current ?: new self();
    }

    /**
     * 计算前缀
     *
     * @param string $filename
     *                         路由所属文件名（相对路径）
     *
     * @return string
     */
    public static function toPrefix($filename)
    {
        // 去除扩展名
        $extname = strstr(basename($filename), '.');
        $pathname = substr($filename, 0, -strlen($extname));
        // 得到本组路由的前缀
        $prefix = str_replace(DIRECTORY_SEPARATOR, '/', $pathname);

        return strtolower(rtrim($prefix, '/'));
    }

    /**
     * 将网址转为正则式，替换其中的占位符.
     *
     * @param string $url
     *                        要转换的网址
     * @param bool   $is_wild
     *                        是否匹配以此开头的所有网址
     *
     * @return string
     */
    public static function compileUrl($url, $is_wild = false)
    {
        $url = preg_quote(strtolower(rtrim($url, '/')));
        // 替换占位符
        $keys = array_map('preg_quote', array_keys(self::$aliases));
        $values = array_values(self::$aliases);
        $url = str_replace($keys, $values, $url);
        // 完全匹配还是匹配开头
        $wildcard = ($is_wild === false) ? '' : '(.*)?';

        return '!^' . $url . '/?' . $wildcard . '$!';
    }

    /**
     * 增加一个路由项.
     *
     * @param string $path
     *                        路由项对应的网址
     * @param string $handler
     *                        控制器
     *
     * @return string
     */
    public function route($path, $handler)
    {
        $rule = self::compileUrl($path);
        if (func_num_args() > 2) {
            $handlers = array_slice(func_get_args(), 1);
        } else {
            $handlers = [$handler];
        }
        $this->items[$rule] = $handlers;

        return $rule;
    }

    /**
     * 增加一个路由模块.
     *
     * @param string $directory
     *                          要扫描的目录（相对路径）
     * @param string $wildcard
     *                          文件名格式
     *
     * @return $this
     */
    public function expose($directory, $wildcard = '*.php')
    {
        // 扫描目录下符合格式的文件
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $files = glob($directory . '/' . $wildcard, GLOB_BRACE);
        if (!empty($files)) { // 记录含有路由的文件和对应前缀
            $dirlen = strlen($directory); // 前缀是相对于上级路由器前缀的
            foreach ($files as $filename) {
                $prefix = self::toPrefix(substr($filename, $dirlen));
                $this->children[$prefix] = $filename;
            }
        }

        return $this;
    }

    /**
     * 寻找与网址匹配的handler和filters
     * 使用贪婪匹配，子目录中的路由器优先，长的路由项优先
     * 没有找到匹配项时，返回空数组，否则返回数组中含有以下元素
     * handler 控制器 filters 过滤器数组 url 网址 args 占位符对应值数组.
     *
     * @param string $path
     *                          要查找的网址
     * @param bool   $is_sorted
     *                          是否已经逆序排列过
     *
     * @return array
     */
    public function dispatch($path, $is_sorted = false)
    {
        $path = rtrim(strtolower($path), '/') . '/';
        // 先寻找匹配的路由器
        if (!$is_sorted) {
            krsort($this->children); // 贪婪匹配，需要逆向排序
        }
        foreach ($this->children as $prefix => $filename) {
            if (Common::startsWith($path, $prefix)) {
                $router = new self($filename, $prefix);
                $path = substr($path, strlen($prefix));
                return $router->dispatch($path);
            }
        }
        // 再寻找匹配的路由项
        if (!$is_sorted) {
            krsort($this->items); // 贪婪匹配，需要逆向排序
        }
        foreach ($this->items as $rule => $handlers) {
            if (preg_match($rule, $path, $args) === 1) {
                // 第一项为匹配的网址
                $uri = $this->prefix . array_shift($args);
                return [
                    'handlers' => $handlers,
                    'args' => $args,
                    'rule' => $rule,
                    'path' => $path,
                    'uri' => $uri,
                ];
            }
        }

        return; // 没有找到匹配项
    }
}
