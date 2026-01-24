<?php

/**
 * 接口文档生成命令
 * 
 * 功能说明：
 * - 自动扫描路由文件，生成完整的 API 接口文档
 * - 只生成 HTML 格式，带左侧导航栏和搜索功能
 * - 从路由文件的 option 中读取别名和描述
 * - 支持按控制器分组显示
 * 
 * 使用场景：
 * - 开发阶段：快速查看所有接口列表和参数
 * - 文档交付：生成规范的 API 文档给前端或第三方
 * - 接口对接：生成标准化的接口说明文档
 * 
 * 注意事项：
 * - 确保所有路由都已配置正确的 _alias 和 _desc 选项
 * - 默认输出到 public/docs/api.html
 * - HTML 格式包含左侧导航和搜索功能
 * - 如需添加新接口，只需在路由文件中配置即可
 * 
 * 使用示例：
 * ```bash
 * # 生成文档（默认输出到 public/docs/api.html）
 * php think docs
 * 
 * # 生成到指定路径
 * php think docs --output=docs/my-api.html
 * ```
 * 
 * @package app\command
 */

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\facade\Route;

class Docs extends Command
{
    /**
     * 是否启用反射提取参数
     * @var bool
     */
    protected $enableReflection = false;

    /**
     * 配置命令
     * 
     * 定义命令名称、描述、参数和选项
     */
    protected function configure()
    {
        $this->setName('docs')
            ->setDescription('生成 API 接口文档（HTML 和 OpenAPI 格式）')
            ->addOption('output', 'o', Option::VALUE_OPTIONAL, '指定 HTML 输出文件路径', 'public/docs/api.html')
            ->addOption('openapi', null, Option::VALUE_OPTIONAL, '指定 OpenAPI 输出文件路径', 'public/docs/openapi.json')
            ->addOption('enable-reflection', null, Option::VALUE_NONE, '启用反射提取参数（可能触发自动加载，默认关闭）');
    }

    /**
     * 执行命令
     * 
     * 主要流程：
     * 1. 获取命令参数（输出路径）
     * 2. 从路由文件中读取路由信息
     * 3. 调用 generateHtml() 生成 HTML 文档
     * 4. 将文档写入文件
     * 5. 输出成功信息
     *
     * @param Input $input 输入对象
     * @param Output $output 输出对象
     * @return int
     */
    protected function execute(Input $input, Output $output)
    {
        $outputPath = $input->getOption('output');
        $openapiPath = $input->getOption('openapi');
        $this->enableReflection = $input->getOption('enable-reflection');

        $output->writeln('<info>开始生成接口文档...</info>');
        $output->writeln('<comment>读取路由文件...</comment>');
        if ($this->enableReflection) {
            $output->writeln('<comment>反射提取参数：已启用</comment>');
        } else {
            $output->writeln('<comment>反射提取参数：已禁用（使用 --enable-reflection 选项启用）</comment>');
        }

        // 从路由文件中读取路由信息
        $docs = $this->getDocsFromRoutes();
        
        $output->writeln('<comment>解析到的分组数量: ' . count($docs['groups']) . '</comment>');
        foreach ($docs['groups'] as $groupName => $group) {
            $output->writeln('<comment>  分组: ' . $groupName . ', 路由数量: ' . count($group['routes']) . '</comment>');
        }

        $output->writeln('<comment>生成 HTML 文档...</comment>');

        // 生成 HTML 文档
        $htmlContent = $this->generateHtml($docs);

        // 确保目录存在
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // 写入 HTML 文件
        file_put_contents($outputPath, $htmlContent);
        $output->writeln('<info>HTML 文档生成成功！</info>');
        $output->writeln('<comment>文件路径: ' . $outputPath . '</comment>');

        // 生成 OpenAPI 文档
        $output->writeln('<comment>生成 OpenAPI 文档...</comment>');
        $openapiContent = $this->generateOpenApi($docs);
        
        // 确保目录存在
        $openapiDir = dirname($openapiPath);
        if (!is_dir($openapiDir)) {
            mkdir($openapiDir, 0755, true);
        }
        
        // 写入 OpenAPI 文件
        file_put_contents($openapiPath, $openapiContent);
        $output->writeln('<info>OpenAPI 文档生成成功！</info>');
        $output->writeln('<comment>文件路径: ' . $openapiPath . '</comment>');
        $output->writeln('<info>可以直接导入 Apifox、Postman 等接口测试工具</info>');
    }

    /**
     * 从路由文件中读取文档信息
     * 
     * 直接解析路由文件，提取路由和分组信息
     * 支持多层嵌套分组和子文件夹
     * 
     * @return array 文档数据数组
     */
    protected function getDocsFromRoutes()
    {
        // 从配置文件读取基本信息
        $docs = Config::get('docs');
        
        // 初始化 groups 数组
        $docs['groups'] = [];

        // 路由文件路径
        $routePath = app()->getRootPath() . 'route' . DIRECTORY_SEPARATOR;
        
        // 解析所有路由文件（包括子文件夹）
        if (is_dir($routePath)) {
            $this->scanRouteDirectory($routePath, $docs['groups']);
        }

        // 按分组名称排序
        ksort($docs['groups']);

        return $docs;
    }

    /**
     * 递归扫描路由目录
     * 
     * @param string $directory 目录路径
     * @param array $groups 分组数组（引用）
     * @param string $parentGroup 父级分组名称
     */
    protected function scanRouteDirectory($directory, &$groups, $parentGroup = '')
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = scandir($directory);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($filePath)) {
                // 如果是子文件夹，递归扫描
                $folderName = $file;
                $newParentGroup = empty($parentGroup) ? $folderName : $parentGroup . '/' . $folderName;
                $this->scanRouteDirectory($filePath, $groups, $newParentGroup);
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                // 如果是 PHP 文件，解析路由
                $this->parseRouteFile($filePath, $groups, $parentGroup);
            }
        }
    }

    /**
     * 解析单个路由文件
     * 
     * @param string $filePath 路由文件路径
     * @param array $groups 分组数组（引用）
     * @param string $parentGroup 父级分组名称（用于多层目录结构）
     */
    protected function parseRouteFile($filePath, &$groups, $parentGroup = '')
    {
        $content = file_get_contents($filePath);
        
        // 简化处理：先移除注释
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        $content = preg_replace('/\/\/.*$/m', '', $content);
        
        // 分步处理：先找到所有 Route:: 开头的调用
        $pattern = '/Route::(group|resource|get|post|put|delete|patch|options|head)\s*\(/';
        
        $offset = 0;
        while (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $type = $matches[1][0];
            $startPos = $matches[0][1];
            
            // 找到第一个引号（路径的开始）
            $pathMatch = [];
            $searchStart = $startPos + strlen($matches[0][0]);
            
            if (!preg_match('/[\'"](.*?)[\'"]\s*,/', $content, $pathMatch, 0, $searchStart)) {
                $offset = $startPos + strlen($matches[0][0]);
                continue;
            }
            
            $path = $pathMatch[1];
            $afterPathPos = strpos($content, $pathMatch[0], $startPos) + strlen($pathMatch[0]);
            
            // 找到回调函数的结束位置
            $callbackEnd = $this->findMatchingBrace($content, $afterPathPos);
            if ($callbackEnd === false) {
                $offset = $startPos + strlen($matches[0][0]);
                continue;
            }
            
            $callback = trim(substr($content, $afterPathPos, $callbackEnd - $afterPathPos));
            
            // 检查紧随其后的 option 调用（链式调用）
            $options = $this->parseOptionAfter($content, $callbackEnd);
            
            // 保存 HTTP 方法
            $options['_method'] = strtoupper($type);
            
            if ($type === 'group') {
                // 处理分组：
                // - $path: 分组路径（如 user）
                // - $parentGroup: 文件父级路径（如 admin）
                // - 最终分组路径应该是: admin/user
                // - 文件父级路径也用于生成分组名称（如 "admin/用户管理"）
                // 传递文件父级路径作为 parentPath，让 processGroup 正确拼接
                $groupPrefix = $options['prefix'] ?? '';
                $this->processGroup($path, $callback, $options, $groups, $parentGroup, $groupPrefix, $parentGroup);
            } elseif ($type === 'resource') {
                // 处理资源路由：添加文件父级路径到资源路径
                $resourcePath = empty($parentGroup) ? $path : $parentGroup . '/' . $path;
                $this->processResource($resourcePath, $callback, $options, $groups, $parentGroup);
            } else {
                // 对于非分组路由：添加文件父级路径到路由路径中
                $routePath = empty($parentGroup) ? $path : $parentGroup . '/' . $path;
                $this->processRoute($routePath, $callback, $options, $groups, '', '', '', $parentGroup);
            }
            
            $offset = $callbackEnd;
        }
    }

    /**
     * 查找匹配的右括号
     */
    protected function findMatchingBrace($content, $startPos)
    {
        $pos = $startPos;
        $depth = 0;
        $inString = false;
        $stringChar = '';
        
        while ($pos < strlen($content)) {
            $char = $content[$pos];
            
            if (!$inString) {
                if ($char === "'" || $char === '"') {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    if ($depth === 0) {
                        return $pos;
                    }
                    $depth--;
                }
            } else {
                if ($char === $stringChar && $content[$pos - 1] !== '\\') {
                    $inString = false;
                }
            }
            
            $pos++;
        }
        
        return false;
    }

    /**
     * 查找匹配的右方括号
     */
    protected function findMatchingBracket($content, $startPos)
    {
        $pos = $startPos + 1; // 从左方括号的下一个字符开始
        $depth = 0;
        $inString = false;
        $stringChar = '';
        
        while ($pos < strlen($content)) {
            $char = $content[$pos];
            
            if (!$inString) {
                if ($char === "'" || $char === '"') {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === '[') {
                    $depth++;
                } elseif ($char === ']') {
                    if ($depth === 0) {
                        return $pos;
                    }
                    $depth--;
                }
            } else {
                if ($char === $stringChar && $content[$pos - 1] !== '\\') {
                    $inString = false;
                }
            }
            
            $pos++;
        }
        
        return false;
    }

    /**
     * 解析选项字符串
     */
    protected function parseOptions($str)
    {
        $options = [];
        
        // 简单的键值对解析
        $pattern = '/\'([^\']+)\'\s*=>\s*(.+?)(?:,|$)/m';
        preg_match_all($pattern, $str, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2];
            
            // 移除字符串引号
            $value = trim($value, '"\'');
            
            $options[$key] = $value;
        }
        
        return $options;
    }

    /**
     * 解析回调函数后的 option 调用
     * 
     * @param string $content 内容
     * @param int $callbackEnd 回调函数结束位置
     * @return array 选项数组
     */
    protected function parseOptionAfter($content, $callbackEnd)
    {
        $options = [];
        
        // 查看回调函数后的内容
        $lookAhead = substr($content, $callbackEnd, 500);
        
        // 解析 option 调用 - 匹配 )->option([ 这种链式调用
        // 关键改进：匹配到遇到另一个 Route:: 或闭包结束为止
        // 这样可以匹配分组的链式调用（如 ->prefix()->option()），但不会匹配到其他路由
        $routePattern = '/Route::(?:group|get|post|put|delete|patch|options|head)\s*\(/';
        
        // 找到下一个 Route:: 定义的位置，限制匹配范围
        $nextRoutePos = strpos($lookAhead, 'Route::');
        if ($nextRoutePos !== false) {
            $searchRange = substr($lookAhead, 0, $nextRoutePos);
        } else {
            $searchRange = $lookAhead;
        }
        
        // 在限制范围内查找 option 调用
        if (preg_match('/->option\s*\(\s*\[/s', $searchRange, $match, PREG_OFFSET_CAPTURE)) {
            $optionStartPos = $callbackEnd + $match[0][1] + strlen($match[0][0]) - 1; // 定位到 [
            $optionEnd = $this->findMatchingBracket($content, $optionStartPos);
            
            if ($optionEnd !== false) {
                $optionsStr = substr($content, $optionStartPos + 1, $optionEnd - $optionStartPos - 1);
                $options = $this->parseOptions($optionsStr);
            }
        }
        
        // 解析 prefix 调用 - 同样在限制范围内查找
        if (preg_match('/->prefix\s*\(\s*[\'"]([^\'"]+)[\'"]\)/s', $searchRange, $matches)) {
            $options['prefix'] = $matches[1];
        }
        
        return $options;
    }

    /**
     * 处理资源路由（Route::resource）
     * 
     * Resource 路由会自动展开为以下路由：
     * - index   (GET)    列表
     * - create  (GET)    创建页面
     * - save    (POST)   保存
     * - read    (GET)    详情（带 :id）
     * - edit    (GET)    编辑页面（带 :id）
     * - update  (PUT)    更新（带 :id）
     * - delete  (DELETE) 删除（带 :id）
     * 
     * @param string $resourcePath 资源路径
     * @param string $callback 控制器名
     * @param array $options 路由选项
     * @param array $groups 分组数组（引用）
     * @param string $fileParentPath 文件父级路径
     */
    protected function processResource($resourcePath, $callback, $options, &$groups, $fileParentPath = '')
    {
        // 移除引号，获取控制器名
        $controllerName = trim($callback, '"\'');
        
        // 资源路由的默认方法映射
        $resourceRoutes = [
            [
                'method' => 'GET',
                'action' => 'index',
                'path' => $resourcePath,
                'alias' => $options['_alias_index'] ?? '获取' . $resourcePath . '列表',
                'desc' => $options['_desc_index'] ?? '获取' . $resourcePath . '列表数据',
            ],
            [
                'method' => 'GET',
                'action' => 'create',
                'path' => $resourcePath . '/create',
                'alias' => $options['_alias_create'] ?? '创建' . $resourcePath,
                'desc' => $options['_desc_create'] ?? '显示创建' . $resourcePath . '表单',
            ],
            [
                'method' => 'POST',
                'action' => 'save',
                'path' => $resourcePath,
                'alias' => $options['_alias_save'] ?? '保存' . $resourcePath,
                'desc' => $options['_desc_save'] ?? '保存' . $resourcePath . '数据',
            ],
            [
                'method' => 'GET',
                'action' => 'read',
                'path' => $resourcePath . '/:id',
                'alias' => $options['_alias_read'] ?? '获取' . $resourcePath . '详情',
                'desc' => $options['_desc_read'] ?? '获取指定' . $resourcePath . '的详细信息',
            ],
            [
                'method' => 'GET',
                'action' => 'edit',
                'path' => $resourcePath . '/:id/edit',
                'alias' => $options['_alias_edit'] ?? '编辑' . $resourcePath,
                'desc' => $options['_desc_edit'] ?? '显示编辑' . $resourcePath . '表单',
            ],
            [
                'method' => 'PUT',
                'action' => 'update',
                'path' => $resourcePath . '/:id',
                'alias' => $options['_alias_update'] ?? '更新' . $resourcePath,
                'desc' => $options['_desc_update'] ?? '更新指定' . $resourcePath . '数据',
            ],
            [
                'method' => 'DELETE',
                'action' => 'delete',
                'path' => $resourcePath . '/:id',
                'alias' => $options['_alias_delete'] ?? '删除' . $resourcePath,
                'desc' => $options['_desc_delete'] ?? '删除指定' . $resourcePath,
            ],
        ];
        
        // 支持只选项（只生成部分路由）
        $only = $options['only'] ?? [];
        $except = $options['except'] ?? [];
        
        // 遍历所有资源路由
        foreach ($resourceRoutes as $routeInfo) {
            // 检查是否在 only 列表中
            if (!empty($only) && !in_array($routeInfo['action'], $only)) {
                continue;
            }
            
            // 检查是否在 except 列表中
            if (!empty($except) && in_array($routeInfo['action'], $except)) {
                continue;
            }
            
            // 构建路由选项
            $routeOptions = [
                '_method' => $routeInfo['method'],
                '_alias' => $routeInfo['alias'],
                '_desc' => $routeInfo['desc'],
            ];
            
            // 合并其他选项
            if (isset($options['_params'])) {
                $routeOptions['_params'] = $options['_params'];
            }
            if (isset($options['_response'])) {
                $routeOptions['_response'] = $options['_response'];
            }
            
            // 处理单个路由
            $this->processRoute(
                $routeInfo['path'],
                $routeInfo['action'],
                $routeOptions,
                $groups,
                '',
                '',
                $controllerName,
                $fileParentPath
            );
        }
    }

    /**
     * 处理路由分组
     * 
     * @param string $path 分组路径
     * @param string $callback 回调函数内容
     * @param array $options 分组选项
     * @param array $groups 分组数组（引用）
     * @param string $parentPath 父级路径
     * @param string $parentAlias 父级别名
     * @param string $fileParentPath 路由文件所在的父级路径（用于反映文件物理位置）
     */
    protected function processGroup($path, $callback, $options, &$groups, $parentPath = '', $parentAlias = '', $fileParentPath = '')
    {
        // 确定分组路径
        $currentPath = empty($parentPath) ? $path : $parentPath . '/' . $path;
        
        // 确定分组名称：如果有文件父级路径（如admin），则组合成 "admin/用户管理"
        if (!empty($fileParentPath)) {
            $currentAlias = $options['_alias'] ?? (!empty($parentAlias) ? $parentAlias : $path);
            $groupName = $fileParentPath . '/' . $currentAlias;
        } else {
            $currentAlias = $options['_alias'] ?? (!empty($parentAlias) ? $parentAlias : $path);
            $groupName = $currentAlias;
        }
        
        // 获取 prefix（控制器前缀）
        $prefix = $options['prefix'] ?? '';
        
        // 解析分组内的路由
        $this->parseRoutesInGroup($callback, $options, $groups, $currentPath, $groupName, $prefix);
    }

    /**
     * 解析分组内的路由
     * 
     * @param string $callback 回调内容
     * @param array $groupOptions 分组选项
     * @param array $groups 分组数组（引用）
     * @param string $groupPath 分组路径
     * @param string $groupAlias 分组名称（已经包含了文件父级路径）
     * @param string $prefix 控制器前缀
     */
    protected function parseRoutesInGroup($callback, $groupOptions, &$groups, $groupPath, $groupAlias, $prefix)
    {
        // 使用与 parseRouteFile 相同的逻辑
        $pattern = '/Route::(group|get|post|put|delete|patch|options|head)\s*\(/';
        
        $offset = 0;
        $count = 0;
        while (preg_match($pattern, $callback, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $type = $matches[1][0];
            $startPos = $matches[0][1];
            
            // 找到第一个引号（路径的开始）
            $pathMatch = [];
            if (!preg_match('/[\'"](.*?)[\'"]\s*,/', $callback, $pathMatch, 0, $startPos + strlen($matches[0][0]))) {
                $offset = $startPos + strlen($matches[0][0]);
                continue;
            }
            
            $routePath = $pathMatch[1];
            $afterPathPos = strpos($callback, $pathMatch[0], $startPos) + strlen($pathMatch[0]);
            
            // 找到回调函数的结束位置
            $routeCallbackEnd = $this->findMatchingBrace($callback, $afterPathPos);
            if ($routeCallbackEnd === false) {
                $offset = $startPos + strlen($matches[0][0]);
                continue;
            }
            
            $routeCallback = trim(substr($callback, $afterPathPos, $routeCallbackEnd - $afterPathPos));
            
            // 检查紧随其后的 option 调用（链式调用）
            $options = $this->parseOptionAfter($callback, $routeCallbackEnd);
            
            // 保存 HTTP 方法
            $options['_method'] = strtoupper($type);
            
            // 合并分组选项，但不包括 _alias 和 _desc（这些是分组独有的）
            $finalOptions = array_merge($groupOptions, $options);
            
            // 如果路由自己没有 _alias，则不使用分组的 _alias
            // 闭包函数或没有配置的路由应该使用路径作为别名
            if (!isset($options['_alias'])) {
                unset($finalOptions['_alias']);
            }
            
            // 如果路由自己没有 _desc，则不使用分组的 _desc
            if (!isset($options['_desc'])) {
                unset($finalOptions['_desc']);
            }
            
            if ($type === 'group') {
                // 嵌套分组，继续处理（传递空的文件父级路径，避免重复）
                $this->processGroup($routePath, $routeCallback, $finalOptions, $groups, $groupPath, $groupAlias, '');
            } else {
                // 普通路由 - 需要传递文件父级路径
                // 从 parseRoutesInGroup 调用时，没有文件父级路径参数，需要从 $groupPath 中提取
                // $groupPath 可能是 "admin/user"，文件父级路径是 "admin"
                // 或者 $groupPath 是 "user"，文件父级路径为空（来自 app.php）
                $fileParentPath = '';
                $parts = explode('/', $groupPath);
                if (count($parts) > 1) {
                    // 如果 groupPath 包含多级（如 admin/user），则最后一级是分组名，前面是文件父级路径
                    array_pop($parts);
                    $fileParentPath = implode('/', $parts);
                }
                $this->processRoute($routePath, $routeCallback, $finalOptions, $groups, $groupPath, $groupAlias, $prefix, $fileParentPath);
            }
            
            $offset = $routeCallbackEnd;
            $count++;
        }
    }

    /**
     * 处理单个路由
     * 
     * @param string $routePath 路由路径
     * @param string $callback 回调函数
     * @param array $options 路由选项
     * @param array $groups 分组数组（引用）
     * @param string $groupPath 分组路径
     * @param string $groupAlias 分组名称（已经包含了文件父级路径）
     * @param string $prefix 控制器前缀
     * @param string $fileParentPath 路由文件所在的父级路径（用于反映文件物理位置）
     */
    protected function processRoute($routePath, $callback, $options, &$groups, $groupPath = '', $groupAlias = '', $prefix = '', $fileParentPath = '')
    {
        // 如果路由有自己配置的 _alias，使用它；否则使用路径
        $alias = isset($options['_alias']) ? $options['_alias'] : $routePath;
        
        // 如果路由有自己配置的 _desc，使用它；否则使用空字符串
        $desc = isset($options['_desc']) ? $options['_desc'] : '';
        
        // 确定分组名称
        if (!empty($groupAlias)) {
            $groupName = $groupAlias;
        } elseif (!empty($groupPath)) {
            $groupName = $groupPath;
        } elseif (!empty($fileParentPath)) {
            // 如果没有分组，但有文件父级路径，使用文件父级路径作为分组
            $groupName = $fileParentPath;
        } else {
            $groupName = '其他';
        }
        
        // 确定完整路径
        $fullPath = empty($groupPath) ? $routePath : $groupPath . '/' . $routePath;
        
        // 解析 HTTP 方法
        $httpMethod = $options['_method'] ?? 'GET';
        
        // 检查是否是闭包函数
        $isClosure = preg_match('/function\s*\(/', $callback) || preg_match('/^\s*fn\s*\(/', $callback);
        
        // 获取控制器和方法信息
        $controllerClass = '';
        $action = '';
        $params = $options['_params'] ?? [];
        
        // 从路由路径中提取 ThinkPHP 参数（如 :id, :name 等）
        $pathParams = $this->extractPathParams($fullPath);
        if (!empty($pathParams)) {
            // 合并路径参数到 params 数组
            foreach ($pathParams as $paramName => $paramType) {
                if (!isset($params[$paramName])) {
                    $paramSource = in_array($httpMethod, ['GET', 'DELETE']) ? 'URL参数' : '路径参数';
                    $params[$paramName] = $paramType . '|' . $paramSource . '，必填';
                }
            }
        }
        
            if (!$isClosure && !empty($prefix)) {
                // 移除 prefix 中的尾部斜杠
                $prefix = rtrim($prefix, '/');
                
                // 构建控制器类名
                $parts = explode('.', $prefix);
                if (count($parts) > 1) {
                    $controllerClass = 'app\\controller\\' . implode('\\', $parts);
                } else {
                    $controllerClass = 'app\\controller\\' . $prefix;
                }
                
                // 获取方法名（去除引号）
                $action = trim($callback, '"\'');
                
                // 只在启用反射时才使用反射读取参数
                // 避免触发自动加载和潜在的副作用
                if ($this->enableReflection && !empty($controllerClass) && !empty($action) && class_exists($controllerClass)) {
                    $methodParams = $this->getMethodParams($controllerClass, $action, $httpMethod);
                    // 合并参数：路由配置的 _params 优先（保留用户明确配置的参数）
                    if (!empty($methodParams)) {
                        $params = array_merge($methodParams, $params);
                    }
                } elseif (!$this->enableReflection && !empty($controllerClass) && !empty($action)) {
                    // 反射未启用时，尝试从源文件中提取参数（不触发自动加载）
                    $methodParams = $this->getMethodParamsFromSource($controllerClass, $action, $httpMethod);
                    if (!empty($methodParams)) {
                        $params = array_merge($methodParams, $params);
                    }
                }
            }
        
        // 添加到分组
        if (!isset($groups[$groupName])) {
            $groups[$groupName] = [
                'name' => $groupName,
                'routes' => [],
            ];
        }
        
        $groups[$groupName]['routes'][] = [
            'method' => $httpMethod,
            'path' => $fullPath,
            'alias' => $alias,
            'description' => $desc,
            'controller' => $isClosure ? '闭包函数' : $controllerClass,
            'params' => $params,
            'response' => $options['_response'] ?? [],
            'is_closure' => $isClosure,
        ];
    }

    /**
     * 从路由路径中提取路径参数
     * 
     * 只提取以 : 开头的路径参数（如 :id, :name 等）
     * 不提取普通路由路径中的参数
     * 
     * @param string $path 路由路径
     * @return array 参数数组，格式: [参数名 => '类型']
     */
    protected function extractPathParams($path)
    {
        $params = [];
        
        // 匹配以 : 开头的参数（如 :id, :name）
        // 不匹配普通路径（如 info, find 等）
        if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $path, $matches)) {
            foreach ($matches[1] as $paramName) {
                // 默认类型为 integer，通常路径参数是 ID
                $params[$paramName] = 'int';
            }
        }
        
        return $params;
    }

    /**
     * 通过反射获取控制器方法的参数信息（启用反射时使用）
     * 
     * 注意：此方法会触发类的自动加载，可能在某些环境产生副作用
     * 优先从 PHPDoc @param 标签读取参数，如果没有则分析方法体中调用的 getParam/getPost/getGet 方法
     * 
     * @param string $controllerClass 控制器类名
     * @param string $action 方法名
     * @param string $httpMethod HTTP请求方法（GET/POST/PUT/DELETE）
     * @return array 参数数组
     */
    protected function getMethodParams($controllerClass, $action, $httpMethod)
    {
        $params = [];
        
        try {
            if (!class_exists($controllerClass)) {
                return $params;
            }
            
            $reflection = new \ReflectionClass($controllerClass);
            
            if (!$reflection->hasMethod($action)) {
                return $params;
            }
            
            $method = $reflection->getMethod($action);
            
            // 从 PHPDoc 注释中读取参数
            $docComment = $method->getDocComment();
            
            if ($docComment) {
                $parsedParams = $this->parseDocParams($docComment, $httpMethod);
                if (!empty($parsedParams)) {
                    $params = $parsedParams;
                }
            }
            
            // 如果 PHPDoc 没有参数，分析方法体中的 getParam/getPost/getGet 调用
            if (empty($params)) {
                $bodyParams = $this->extractParamsFromMethodBody($method, $httpMethod);
                if (!empty($bodyParams)) {
                    $params = $bodyParams;
                }
            }
        } catch (\Exception $e) {
            // 记录错误但不中断执行
            error_log("Docs command - Reflection error for {$controllerClass}::{$action}: " . $e->getMessage());
        }

        return $params;
    }

    /**
     * 从源文件中获取控制器方法的参数信息（不触发自动加载）
     * 
     * 此方法直接分析 PHP 源文件内容，不会触发类的自动加载
     * 适合在 CI/CD 环境或不想触发副作用时使用
     * 
     * @param string $controllerClass 控制器类名
     * @param string $action 方法名
     * @param string $httpMethod HTTP请求方法（GET/POST/PUT/DELETE）
     * @return array 参数数组
     */
    protected function getMethodParamsFromSource($controllerClass, $action, $httpMethod)
    {
        $params = [];
        
        try {
            // 将类名转换为文件路径
            $className = ltrim(str_replace('\\', '/', $controllerClass), '/');
            $filePath = app()->getRootPath() . $className . '.php';
            
            if (!file_exists($filePath)) {
                return $params;
            }
            
            $content = file_get_contents($filePath);
            
            // 匹配方法的 PHPDoc 注释
            // 支持多行注释，匹配到方法定义之前
            $pattern = '/\/\*\*(.*?)\*\/\s*(public|protected|private)\s+function\s+' . preg_quote($action, '/') . '\s*\(/s';
            
            if (preg_match($pattern, $content, $matches)) {
                $docComment = $matches[1];
                $params = $this->parseDocParams($docComment, $httpMethod);
            }
        } catch (\Exception $e) {
            // 记录错误但不中断执行
            error_log("Docs command - Source analysis error for {$controllerClass}::{$action}: " . $e->getMessage());
        }
        
        return $params;
    }

    /**
     * 从方法体中提取参数（通过分析 getParam/getPost/getGet 调用）
     * 
     * @param \ReflectionMethod $method 方法反射对象
     * @param string $httpMethod HTTP请求方法
     * @return array 参数数组
     */
    protected function extractParamsFromMethodBody($method, $httpMethod)
    {
        $params = [];
        
        // 获取方法体内容
        $fileName = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        
        $lines = file($fileName);
        $methodBody = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
        
        // 根据HTTP方法确定要匹配的方法调用
        if (in_array($httpMethod, ['GET', 'DELETE'])) {
            // GET/DELETE 请求匹配 getParam 或 getGet
            // 改进正则表达式，使其更宽松
            $pattern = '/\$this->(?:getParam|getGet)\s*\(\s*[\'"]([^\'"]+)[\'"]/';
            $paramSource = 'URL参数';
        } else {
            // POST/PUT 请求匹配 getPost
            // 改进正则表达式，使其更宽松
            $pattern = '/\$this->getPost\s*\(\s*[\'"]([^\'"]+)[\'"]/';
            $paramSource = '请求体参数';
        }
        
        // 匹配所有参数调用
        if (preg_match_all($pattern, $methodBody, $matches)) {
            foreach ($matches[1] as $paramName) {
                if (!isset($params[$paramName])) {
                    // 默认类型为 string
                    $params[$paramName] = 'string|' . $paramSource;
                }
            }
        }
        
        return $params;
    }

    /**
     * 解析 PHPDoc 注释中的 @param 标签
     * 
     * @param string $docComment PHPDoc 注释内容
     * @param string $httpMethod HTTP请求方法
     * @return array 参数数组
     */
    protected function parseDocParams($docComment, $httpMethod)
    {
        $params = [];
        
        // 匹配 @param 标签
        // 格式: @param 类型 $参数名 描述
        // 注意：在单引号字符串中，$ 符号不需要转义
        if (preg_match_all('/@param\s+(\S+)\s+\$(\w+)\s*(.*)/i', $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = $match[1];
                $name = $match[2];
                $desc = trim($match[3]);
                
                // 检查是否包含"可选"字样
                $isRequired = !str_contains($desc, '可选') && !str_contains($desc, '（可选）');
                
                // 根据HTTP方法确定参数类型和来源
                if (in_array($httpMethod, ['GET', 'DELETE'])) {
                    $paramSource = 'URL参数';
                } else {
                    $paramSource = '请求体参数';
                }
                
                // 构建参数描述 - 确保包含参数来源信息
                if (empty($desc)) {
                    $paramDesc = $isRequired ? $paramSource . '，必填' : $paramSource . '，可选';
                } else {
                    // 如果有描述，追加参数来源信息
                    $paramDesc = $desc . '（' . $paramSource . '）';
                }
                
                $params[$name] = $type . '|' . $paramDesc;
            }
        }
        
        return $params;
    }

    /**
     * 生成 HTML 格式文档
     * 
     * 生成带左侧导航栏和搜索功能的 HTML 文档
     * 
     * @param array $docs 文档数据
     * @return string HTML 内容
     */
    protected function generateHtml($docs)
    {
        $groupsJson = json_encode($docs['groups'], JSON_UNESCAPED_UNICODE);
        
        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $docs['title'] . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            height: 100vh;
            overflow: hidden;
            background: #f5f5f5;
        }
        
        /* 左侧导航栏 */
        .sidebar {
            width: 300px;
            background: #2c3e50;
            color: #ecf0f1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            border-right: 1px solid #34495e;
        }
        
        .sidebar-header {
            padding: 20px;
            background: #1a252f;
            border-bottom: 1px solid #34495e;
        }
        
        .sidebar-header h1 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .sidebar-header p {
            font-size: 12px;
            color: #95a5a6;
        }
        
        /* 搜索框 */
        .search-box {
            padding: 15px;
            background: #2c3e50;
            border-bottom: 1px solid #34495e;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .search-box input {
            width: 100%;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            background: #34495e;
            color: #ecf0f1;
            font-size: 14px;
        }
        
        .search-box input::placeholder {
            color: #7f8c8d;
        }
        
        .search-box input:focus {
            outline: none;
            background: #3d566e;
        }
        
        /* 导航菜单 */
        .nav-menu {
            flex: 1;
            overflow-y: auto;
        }
        
        .nav-group {
            border-bottom: 1px solid #34495e;
        }
        
        .nav-group-title {
            padding: 12px 15px;
            font-size: 14px;
            font-weight: bold;
            background: #34495e;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
        }
        
        .nav-group-title:hover {
            background: #3d566e;
        }
        
        .toggle-icon {
            font-size: 10px;
            transition: transform 0.2s;
            display: inline-block;
        }
        
        .nav-group.collapsed .toggle-icon {
            transform: rotate(-90deg);
        }
        
        .nav-group.collapsed .nav-routes {
            display: none;
        }
        
        .nav-routes {
            background: #2c3e50;
        }
        
        .nav-route {
            padding: 10px 15px 10px 30px;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.2s;
            border-left: 3px solid transparent;
        }
        
        .nav-route:hover {
            background: #3d566e;
            border-left-color: #3498db;
        }
        
        .nav-route.active {
            background: #34495e;
            border-left-color: #3498db;
        }
        
        .nav-route-method {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 11px;
            margin-right: 8px;
        }
        
        .nav-route-method.GET {
            background: #61affe;
            color: #fff;
        }
        
        .nav-route-method.POST {
            background: #49cc90;
            color: #fff;
        }
        
        .nav-route-method.PUT {
            background: #fca130;
            color: #fff;
        }
        
        .nav-route-method.DELETE {
            background: #f93e3e;
            color: #fff;
        }
        
        /* 主内容区域 */
        .main-content {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }
        
        .content-wrapper {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        
        .header p {
            font-size: 14px;
            color: #7f8c8d;
            margin: 5px 0;
        }
        
        .group-section {
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .group-title {
            font-size: 24px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
            color: #2c3e50;
        }
        
        .route-card {
            background: #f8f9fa;
            padding: 20px;
            margin: 15px 0;
            border-radius: 6px;
            border-left: 4px solid #3498db;
        }
        
        .route-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .route-method {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 13px;
            margin-right: 12px;
            text-transform: uppercase;
        }
        
        .route-method.GET {
            background: #61affe;
            color: #fff;
        }
        
        .route-method.POST {
            background: #49cc90;
            color: #fff;
        }
        
        .route-method.PUT {
            background: #fca130;
            color: #fff;
        }
        
        .route-method.DELETE {
            background: #f93e3e;
            color: #fff;
        }
        
        .route-path {
            font-family: "Courier New", monospace;
            background: #e9ecef;
            padding: 4px 10px;
            border-radius: 3px;
            font-size: 14px;
            color: #2c3e50;
        }
        
        .route-title {
            flex: 1;
            font-size: 18px;
            margin-left: 15px;
            color: #2c3e50;
        }
        
        .route-description {
            font-size: 14px;
            color: #7f8c8d;
            margin: 10px 0;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #2c3e50;
            margin: 15px 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .params-block, .response-block {
            background: #fff;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            font-family: "Courier New", monospace;
            font-size: 13px;
            overflow-x: auto;
        }
        
        .param-item {
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .param-item:last-child {
            border-bottom: none;
        }
        
        .param-name {
            color: #e67e22;
            font-weight: bold;
        }
        
        .param-type {
            color: #9b59b6;
            margin-left: 10px;
        }
        
        .param-desc {
            color: #7f8c8d;
            margin-left: 10px;
        }
        
        /* 滚动条样式 */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #34495e;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #5d6d7e;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #7f8c8d;
        }
        
        .main-content::-webkit-scrollbar-track {
            background: #ecf0f1;
        }
        
        .main-content::-webkit-scrollbar-thumb {
            background: #bdc3c7;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h1>' . $docs['title'] . '</h1>
            <p>版本: ' . $docs['version'] . '</p>
            <p>' . $docs['base_url'] . '</p>
        </div>
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="搜索接口...">
        </div>
        <div class="nav-menu" id="navMenu">';
        
        foreach ($docs['groups'] as $groupName => $group) {
            $html .= '<div class="nav-group">
                <div class="nav-group-title">' . $groupName . ' <span class="toggle-icon">▼</span></div>
                <div class="nav-routes">';
            
            foreach ($group['routes'] as $route) {
                $routeId = 'route-' . md5($groupName . $route['path'] . $route['method']);
                $html .= '<div class="nav-route" 
                    data-route-id="' . $routeId . '" 
                    data-path="' . htmlspecialchars($route['path']) . '"
                    data-method="' . $route['method'] . '">
                    <span class="nav-route-method ' . $route['method'] . '">' . $route['method'] . '</span>
                    <span class="nav-route-alias">' . htmlspecialchars($route['alias']) . '</span>
                </div>';
            }
            
            $html .= '</div></div>';
        }
        
        $html .= '</div>
    </div>
    
    <div class="main-content">
        <div class="content-wrapper">
            <div class="header">
                <h1>' . $docs['title'] . '</h1>
                <p>' . $docs['description'] . '</p>
                <p>基础URL: ' . $docs['base_url'] . '</p>
            </div>';
        
        foreach ($docs['groups'] as $groupName => $group) {
            $html .= '<div class="group-section" id="group-' . md5($groupName) . '">
                <h2 class="group-title">' . $groupName . '</h2>';
            
            foreach ($group['routes'] as $route) {
                $routeId = 'route-' . md5($groupName . $route['path'] . $route['method']);
                $html .= '<div class="route-card" id="' . $routeId . '">
                    <div class="route-header">
                        <span class="route-method ' . $route['method'] . '">' . $route['method'] . '</span>
                        <span class="route-path">' . htmlspecialchars($route['path']) . '</span>
                        <span class="route-title">' . htmlspecialchars($route['alias']) . '</span>
                    </div>
                    <p class="route-description">' . htmlspecialchars($route['description']) . '</p>';
                
                if (!empty($route['params'])) {
                    $html .= '<div class="section-title">请求参数</div>
                        <div class="params-block">';
                    foreach ($route['params'] as $param => $desc) {
                        $parts = explode('|', $desc);
                        $type = $parts[0] ?? 'string';
                        $paramDesc = $parts[1] ?? '';
                        $html .= '<div class="param-item">
                            <span class="param-name">' . htmlspecialchars($param) . '</span>
                            <span class="param-type">[' . htmlspecialchars($type) . ']</span>
                            <span class="param-desc">' . htmlspecialchars($paramDesc) . '</span>
                        </div>';
                    }
                    $html .= '</div>';
                }
                
                if (!empty($route['response'])) {
                    $html .= '<div class="section-title">响应数据</div>
                        <div class="response-block">' . json_encode($route['response'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</div>';
                }
                
                $html .= '</div>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>
    </div>
    
    <script>
        const navGroups = document.querySelectorAll(".nav-group");
        const navRoutes = document.querySelectorAll(".nav-route");
        const searchInput = document.getElementById("searchInput");
        
        // 分组折叠/展开
        navGroups.forEach(group => {
            const title = group.querySelector(".nav-group-title");
            title.addEventListener("click", () => {
                group.classList.toggle("collapsed");
            });
        });
        
        // 导航点击
        navRoutes.forEach(route => {
            route.addEventListener("click", () => {
                // 移除所有激活状态
                navRoutes.forEach(r => r.classList.remove("active"));
                
                // 添加当前激活状态
                route.classList.add("active");
                
                // 滚动到对应路由
                const routeId = route.dataset.routeId;
                const routeCard = document.getElementById(routeId);
                if (routeCard) {
                    routeCard.scrollIntoView({ behavior: "smooth", block: "start" });
                }
            });
        });
        
        // 搜索功能
        searchInput.addEventListener("input", (e) => {
            const keyword = e.target.value.toLowerCase().trim();
            
            navRoutes.forEach(route => {
                const alias = route.querySelector(".nav-route-alias").textContent.toLowerCase();
                const path = route.dataset.path ? route.dataset.path.toLowerCase() : "";
                const method = route.dataset.method ? route.dataset.method.toLowerCase() : route.querySelector(".nav-route-method").textContent.toLowerCase();
                
                if (keyword === "" || alias.includes(keyword) || path.includes(keyword) || method.includes(keyword)) {
                    route.style.display = "block";
                } else {
                    route.style.display = "none";
                }
            });
            
            // 展开所有包含匹配项的分组
            if (keyword !== "") {
                navGroups.forEach(group => {
                    const visibleRoutes = group.querySelectorAll(".nav-route[style=\'display: block\']");
                    if (visibleRoutes.length > 0) {
                        group.classList.remove("collapsed");
                    }
                });
            }
        });
        
    </script>
</body>
</html>';

        return $html;
    }

    /**
     * 生成 OpenAPI 格式文档
     * 
     * 生成符合 OpenAPI 3.0 规范的 JSON 文档
     * 可以导入到 Apifox、Postman 等接口测试工具
     * 
     * @param array $docs 文档数据
     * @return string JSON 内容
     */
    protected function generateOpenApi($docs)
    {
        $openapi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $docs['title'],
                'description' => $docs['description'],
                'version' => $docs['version'],
            ],
            'servers' => [
                [
                    'url' => $docs['base_url'],
                    'description' => '开发环境',
                ],
            ],
            'paths' => [],
            'tags' => [],
        ];

        // 提取所有唯一的 tags（分组名称）
        foreach ($docs['groups'] as $groupName => $group) {
            $openapi['tags'][] = [
                'name' => $groupName,
                'description' => $groupName . '相关接口',
            ];
        }

        // 遍历所有分组和路由
        foreach ($docs['groups'] as $groupName => $group) {
            foreach ($group['routes'] as $route) {
                $method = strtolower($route['method']);
                $path = '/' . ltrim($route['path'], '/');

                $apiInfo = [
                    'summary' => $route['alias'],
                    'description' => $route['description'],
                    'tags' => [$groupName], // 使用分组名称作为 tag
                ];

                // 处理参数
                $parameters = [];
                $requestBody = null;

                foreach ($route['params'] as $paramName => $paramDesc) {
                    $parts = explode('|', $paramDesc);
                    $type = $parts[0] ?? 'string';
                    $desc = $parts[1] ?? '';

                    // 根据描述确定参数位置
                    if (str_contains($desc, 'URL参数')) {
                        // GET/DELETE 请求使用 query 参数
                        $parameters[] = [
                            'name' => $paramName,
                            'in' => 'query',
                            'description' => $desc,
                            'required' => !str_contains($desc, '可选'),
                            'schema' => [
                                'type' => $this->mapType($type),
                            ],
                        ];
                    } elseif (str_contains($desc, '请求体参数')) {
                        // POST/PUT 请求使用请求体
                        if ($requestBody === null) {
                            $requestBody = [
                                'description' => '请求体参数',
                                'required' => true,
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [],
                                        ],
                                    ],
                                    'application/x-www-form-urlencoded' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [],
                                        ],
                                    ],
                                ],
                            ];
                        }

                        $schema = [
                            'type' => $this->mapType($type),
                            'description' => $desc,
                        ];

                        $requestBody['content']['application/json']['schema']['properties'][$paramName] = $schema;
                        $requestBody['content']['application/x-www-form-urlencoded']['schema']['properties'][$paramName] = $schema;
                    }
                }

                // 添加 parameters 到接口信息
                if (!empty($parameters)) {
                    $apiInfo['parameters'] = $parameters;
                }

                // 添加请求体到接口信息
                if ($requestBody !== null) {
                    $apiInfo['requestBody'] = $requestBody;
                }

                $openapi['paths'][$path][$method] = $apiInfo;
            }
        }

        return json_encode($openapi, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 映射 PHP 类型到 OpenAPI 类型
     * 
     * @param string $phpType PHP 类型
     * @return string OpenAPI 类型
     */
    protected function mapType($phpType)
    {
        $typeMap = [
            'int' => 'integer',
            'integer' => 'integer',
            'float' => 'number',
            'double' => 'number',
            'bool' => 'boolean',
            'boolean' => 'boolean',
            'string' => 'string',
            'array' => 'array',
            'object' => 'object',
        ];

        return $typeMap[strtolower($phpType)] ?? 'string';
    }
}
