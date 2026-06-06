<?php

/**
 * 接口文档生成命令
 *
 * 功能说明：
 * - 使用 ThinkPHP 原生路由方法获取所有路由
 * - 生成完整的 API 接口文档（HTML 和 OpenAPI 格式）
 * - 从控制器方法 PHPDoc 中提取参数
 * - 支持按控制器分组显示
 *
 * 使用示例：
 * ```bash
 * # 生成文档（默认输出到 public/docs/api.html）
 * php think docs
 *
 * # 启用反射提取参数
 * php think docs --enable-reflection
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
     * 路由分组映射（用于分组展示）
     * @var array
     */
    protected $groupMap = [];

    protected $sourceNameMap = [
        "body" => "Body 参数[multipart/form-data]",
        "query" => "Query 参数",
        "path" => "Path 参数",
    ];

    /**
     * 配置命令
     */
    protected function configure()
    {
        $this->setName("docs")
            ->setDescription("生成 API 接口文档（HTML 和 OpenAPI 格式）")
            ->addOption(
                "output",
                "o",
                Option::VALUE_OPTIONAL,
                "指定 HTML 输出文件路径",
                "public/docs/api.html",
            )
            ->addOption(
                "openapi",
                null,
                Option::VALUE_OPTIONAL,
                "指定 OpenAPI 输出文件路径",
                "public/docs/openapi.json",
            )
            ->addOption(
                "enable-reflection",
                null,
                Option::VALUE_NONE,
                "启用反射提取参数（默认启用）",
            );
    }

    /**
     * 执行命令
     */
    protected function execute(Input $input, Output $output)
    {
        $outputPath = $input->getOption("output");
        $openapiPath = $input->getOption("openapi");
        $this->enableReflection =
            $input->getOption("enable-reflection") ?: true; // 默认启用

        $output->writeln("<info>开始生成接口文档...</info>");
        $output->writeln("<comment>读取路由信息...</comment>");
        $output->writeln(
            "<comment>反射提取参数：" .
                ($this->enableReflection ? "已启用" : "已禁用") .
                "</comment>",
        );

        // 从配置文件读取基本信息
        $docs = Config::get("docs");
        $docs["apps"] = [];

        // 根据配置文件初始化应用分组
        foreach ($docs["app"] as $appKey => $appConfig) {
            $appName = $appConfig["alias"] ?? $appKey;
            $docs["apps"][$appKey] = [
                "name" => $appName,
                "key" => $appKey,
                "groups" => [],
            ];
            // 使用 ThinkPHP 原生方法获取路由列表
            $routeList = $this->getRouteList($appKey, $appConfig);
            $output->writeln(
                "<comment>解析到的" .
                    $appConfig["alias"] .
                    "路由数量: " .
                    count($routeList) .
                    "</comment>",
            );
            // 按应用和分组组织路由
            $this->organizeRoutes(
                $routeList,
                $docs,
                $appKey,
                $appConfig["alias"],
            );
        }

        // 显示统计信息
        $totalGroups = 0;
        $totalRoutes = 0;
        foreach ($docs["apps"] as $appName => $app) {
            $totalGroups += count($app["groups"]);
            foreach ($app["groups"] as $group) {
                $totalRoutes += count($group["routes"]);
            }
        }

        $output->writeln(
            "<comment>解析到的应用数量: " . count($docs["apps"]) . "</comment>",
        );
        $output->writeln("<comment>  总分组数: " . $totalGroups . "</comment>");
        $output->writeln("<comment>  总路由数: " . $totalRoutes . "</comment>");

        $output->writeln("<comment>生成 HTML 文档...</comment>");
        $htmlContent = $this->generateHtml($docs);

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, $htmlContent);
        $output->writeln("<info>HTML 文档生成成功！</info>");
        $output->writeln("<comment>文件路径: " . $outputPath . "</comment>");

        $output->writeln("<comment>生成 OpenAPI 文档...</comment>");
        $openapiContent = $this->generateOpenApi($docs);

        $openapiDir = dirname($openapiPath);
        if (!is_dir($openapiDir)) {
            mkdir($openapiDir, 0755, true);
        }

        file_put_contents($openapiPath, $openapiContent);
        $output->writeln("<info>OpenAPI 文档生成成功！</info>");
        $output->writeln("<comment>文件路径: " . $openapiPath . "</comment>");
        $output->writeln(
            "<info>可以直接导入 Apifox、Postman 等接口测试工具</info>",
        );
    }

    /**
     * 使用 ThinkPHP 原生方法获取路由列表
     * 参考 RouteList 命令的实现
     */
    protected function getRouteList($appName, $config): array
    {
        $isFolder = $config["is_folder"];
        // 清除路由缓存
        $this->app->route->clear();
        $this->app->route->lazy(false);

        // 扫描路由目录
        $path =
            $this->app->getRootPath() .
            "route" .
            DIRECTORY_SEPARATOR .
            $appName .
            ".php";
        if (is_file($path)) {
            include $path;
        }
        //        $this->scanRouteDirectory($path, $isFolder);

        // 获取路由列表
        return $this->app->route->getRuleList();
    }

    /**
     * 扫描路由目录
     * 直接包含主要路由文件，保持分组上下文
     */
    protected function scanRouteDirectory(string $path, bool $isFolder): void
    {
        if ($isFolder) {
            // 直接包含顶层的路由文件（如 admin.php、app.php）
            // 这样可以保持外层分组的上下文（如 Route::group('api/', ...)）
            $files = glob($path . "*.php");
            foreach ($files as $file) {
                // 只包含顶层文件，不包含子目录中的文件
                // 子路由文件会通过 load_routes 函数被加载
                if (is_file($file)) {
                    include $file;
                }
            }
        } else {
            include $path;
        }
    }

    /**
     * 组织路由信息
     */
    protected function organizeRoutes(
        array $routeList,
        array &$docs,
        $appName,
        $appAlias = "",
    ): void {
        foreach ($routeList as $route) {
            // 跳过没有 option 的路由（非业务接口）
            //            if (!isset($route['option']) || !is_array($route['option'])) {
            //                continue;
            //            }

            $option = $route["option"] ?? [];

            // 解析控制器和方法
            $controllerClass = "";
            $routeValue = $route["route"];
            $prefix = $option["prefix"] ?? "";
            $appAlias = $appAlias ?: $appName;

            // 方法名
            $action = is_string($routeValue) ? $routeValue : "";

            // 从 prefix 构造控制器类名
            // prefix 格式: admin.auth.AdminController/
            if (!empty($prefix)) {
                $prefix = rtrim($prefix, "/");

                // prefix 已包含模块名（如 admin.auth.AdminController）
                // 将 . 和 / 转换为 \ 并添加命名空间前缀
                $controllerClass =
                    "app\\controller\\" .
                    str_replace([".", "/"], "\\", $prefix);
            }

            // 解析路由规则中的 :paramName / <paramName> 占位符，作为 path 参数白名单
            // 透传到反射阶段，命中即强制 source=path，修正 GET 接口默认归到 query 的问题
            $pathParamNames = $this->extractPathParamsFromRule(
                (string) $route["rule"],
            );

            // 获取参数
            $params = [];
            if (
                $this->enableReflection &&
                !empty($controllerClass) &&
                !empty($action)
            ) {
                $params = $this->getMethodParams(
                    $controllerClass,
                    $action,
                    $route["method"],
                    $pathParamNames,
                );
            }

            // 兜底：路由占位符命中但反射没识别出来时（比如控制器方法体没用 $request->route()），
            // 也把这些参数作为 path 参数补进来，保证 OpenAPI 路径完整
            $params = $this->backfillPathParams($params, $pathParamNames);

            // 从 option 中获取参数（优先级更高）
            if (isset($option["_params"]) && is_array($option["_params"])) {
                $params = array_merge($params, $option["_params"]);
            }

            // 路由规则
            $rule = ltrim($route["rule"], "/");

            // 在路由规则前添加应用名前缀（如 admin）
            // 这样完整的路径就是: admin/api/auth/admin/login
            if (!str_starts_with($rule, $appName . "/")) {
                $rule = $appName . "/" . $rule;
            }

            // 别名兜底：未配置 _alias 时使用 action 名（如 wechatLogin），
            // 保证未做精细化标注的路由（如 client 模块）也能进入文档；
            // 后续可通过补 _alias 美化中文名
            $alias = $option["_alias"] ?? ($action !== "" ? $action : $rule);

            // 分组名：从控制器类名中提取模块名
            if (isset($option["_group_name"])) {
                $groupName = $option["_group_name"];
            } elseif (!empty($controllerClass)) {
                // 从控制器类名中提取模块名
                // 例如：app\controller\admin\auth\AdminController -> admin
                $namespaceParts = explode("\\", $controllerClass);
                $controllerName = end($namespaceParts); // AdminController

                // 移除 "Controller" 后缀
                $groupName = str_replace("Controller", "", $controllerName);
            } else {
                $groupName = "其他";
            }

            // 初始化应用
            if (!isset($docs["apps"][$appName])) {
                $docs["apps"][$appName] = [
                    "name" => $appAlias,
                    "key" => $appName,
                    "groups" => [],
                ];
            }

            // 初始化分组
            if (!isset($docs["apps"][$appName]["groups"][$groupName])) {
                $docs["apps"][$appName]["groups"][$groupName] = [
                    "name" => $groupName,
                    "routes" => [],
                ];
            }

            // 将 path 参数拼接到路由路径中
            $path = $this->buildPathWithParams($rule, $params);
            // 添加路由（使用修改后的 rule，包含应用名前缀）
            $docs["apps"][$appName]["groups"][$groupName]["routes"][] = [
                "method" => strtoupper($route["method"]),
                "path" => $path,
                "alias" => $alias,
                "description" => $option["_desc"] ?? "",
                "controller" => $controllerClass,
                "action" => $action,
                "params" => $params,
                "response" => $option["_response"] ?? [],
                "is_closure" =>
                    is_object($routeValue) && $routeValue instanceof \Closure,
            ];
        }
    }

    /**
     * 通过反射获取控制器方法的参数信息
     *
     * @param array<int, string> $pathParamNames 路由规则中的 path 占位符名（用于强制 source=path）
     */
    protected function getMethodParams(
        string $controllerClass,
        string $action,
        string $httpMethod,
        array $pathParamNames = [],
    ): array {
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
                $parsedParams = $this->parseDocParams(
                    $docComment,
                    $httpMethod,
                    $pathParamNames,
                );
                if (!empty($parsedParams)) {
                    $params = $parsedParams;
                }
            }

            // 如果 PHPDoc 没有参数，分析方法体中的 getParam/getPost/getGet 调用
            if (empty($params)) {
                $bodyParams = $this->extractParamsFromMethodBody(
                    $method,
                    $httpMethod,
                    $pathParamNames,
                );
                if (!empty($bodyParams)) {
                    $params = $bodyParams;
                }
            }
        } catch (\Exception $e) {
            // 记录错误但不中断执行
            error_log(
                "Docs command - Reflection error for {$controllerClass}::{$action}: " .
                    $e->getMessage(),
            );
        }

        return $params;
    }

    /**
     * 从路由规则中提取 path 占位符名
     *
     * 支持 ThinkPHP 路由的两种占位语法：
     *  - 冒号式：`info/:id`、`update/:id`
     *  - 尖括号式：`info/<id>`（部分项目风格）
     *
     * @return array<int, string>
     */
    protected function extractPathParamsFromRule(string $rule): array
    {
        $names = [];
        if (preg_match_all("/:([A-Za-z_][A-Za-z0-9_]*)/", $rule, $m1)) {
            $names = array_merge($names, $m1[1]);
        }
        if (preg_match_all("/<([A-Za-z_][A-Za-z0-9_]*)>/", $rule, $m2)) {
            $names = array_merge($names, $m2[1]);
        }
        return array_values(array_unique($names));
    }

    /**
     * 兜底补齐 path 参数：路由占位符里有，但反射阶段没识别出来的，
     * 直接以 int 类型补进 params['path']，保证 OpenAPI 路径参数完整
     *
     * @param array $params
     * @param array<int, string> $pathParamNames
     */
    protected function backfillPathParams(
        array $params,
        array $pathParamNames,
    ): array {
        if ($pathParamNames === []) {
            return $params;
        }

        $existing = $params["path"] ?? [];

        // 同时把误归到 query / body 的 path 占位符迁移过来
        foreach (["query", "body"] as $wrongSource) {
            if (
                !isset($params[$wrongSource]) ||
                !is_array($params[$wrongSource])
            ) {
                continue;
            }
            foreach ($params[$wrongSource] as $name => $meta) {
                if (in_array($name, $pathParamNames, true)) {
                    $existing[$name] =
                        [
                            "type" => "int",
                            "required" => true,
                            "default" => null,
                            "desc" => "",
                        ] + (array) $meta;
                    $existing[$name]["required"] = true;
                    unset($params[$wrongSource][$name]);
                }
            }
            if ($params[$wrongSource] === []) {
                unset($params[$wrongSource]);
            }
        }

        // 占位符还没出现的，按 int+required 兜底
        foreach ($pathParamNames as $name) {
            if (!isset($existing[$name])) {
                $existing[$name] = [
                    "type" => "int",
                    "required" => true,
                    "default" => null,
                    "desc" => "",
                ];
            }
        }

        if ($existing !== []) {
            $params["path"] = $existing;
        }
        return $params;
    }

    /**
     * 从方法体中提取参数
     *
     * @param array<int, string> $pathParamNames 路由规则中的 path 占位符名（命中即强制 source=path）
     */
    protected function extractParamsFromMethodBody(
        \ReflectionMethod $method,
        string $httpMethod,
        array $pathParamNames = [],
    ): array {
        $params = [];

        $file = $method->getFileName();
        if (!$file || !is_file($file)) {
            return [];
        }

        $lines = file($file);
        $body = implode(
            "",
            array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ),
        );

        /** 方法 => 参数来源 */
        $sourceMap = [
            "param" => in_array($httpMethod, ["get", "delete"])
                ? "query"
                : "body",
            "get" => "query",
            "post" => "body",
            "route" => "path",
        ];

        /** 类型映射 */
        $typeMap = [
            "d" => "int",
            "f" => "float",
            "b" => "bool",
            "s" => "string",
        ];

        /* ==========================================================
         * 解析单参数写法：param('id', 1)
         * ========================================================== */
        preg_match_all(
            '/->(param|get|post|route)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*([^)]+))?\)/',
            $body,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $m) {
            [$full, $methodName, $raw, $default] = array_pad($m, 4, null);

            [$name, $type] = $this->parseField($raw, $typeMap);
            $source = $sourceMap[$methodName];

            // 路由占位符命中：强制走 path
            if (in_array($name, $pathParamNames, true)) {
                $source = "path";
            }

            $required = $default === null;
            if ($source === "path") {
                $required = true;
            }
            $params[$source][$name] = [
                "type" => $type,
                "required" => $required,
                "default" =>
                    $default !== null ? $this->parsePhpValue($default) : "null",
                "desc" => "",
            ];
        }

        /* ==========================================================
         * 解析数组写法：param([...])
         * ========================================================== */
        preg_match_all(
            "/->(param|get|post|route)\s*\(\s*\[(.*?)\]\s*\)/s",
            $body,
            $arrayMatches,
            PREG_SET_ORDER,
        );

        foreach ($arrayMatches as $am) {
            $methodName = $am[1];
            $arrayBody = trim($am[2]);

            // 拆最外层数组（避免子数组逗号干扰）
            $items = preg_split(
                "/,(?![^\[]*\])/",
                $arrayBody,
                -1,
                PREG_SPLIT_NO_EMPTY,
            );

            foreach ($items as $item) {
                $item = trim($item);
                if ($item === "") {
                    continue;
                }

                /**
                 * 支持：
                 * 'field'
                 * 'field/type'
                 * 'field' => 1
                 * ['field/d' => 111]
                 */
                if (
                    !preg_match(
                        '/[\'"]([^\'"]+)[\'"]\s*(?:=>\s*([^\],\n]+))?/',
                        $item,
                        $m,
                    )
                ) {
                    continue;
                }

                $raw = $m[1];
                $default = isset($m[2]) ? trim($m[2]) : null;

                // 解析 field/type
                [$name, $type] = $this->parseField($raw, $typeMap);

                $source = $sourceMap[$methodName];
                // 路由占位符命中：强制走 path
                if (in_array($name, $pathParamNames, true)) {
                    $source = "path";
                }
                $required = $default === null;

                if ($source === "path") {
                    $required = true; // OpenAPI 规定
                }

                $params[$source][$name] = [
                    "type" => $type,
                    "required" => $required,
                    "default" =>
                        $default !== null
                            ? $this->parsePhpValue($default)
                            : null,
                    "desc" => "",
                ];
            }
        }

        return $params;
    }

    /**
     * 解析 PHPDoc 注释中的 @param 标签
     *
     * @param array<int, string> $pathParamNames 路由规则中的 path 占位符名（命中即强制 source=path）
     */
    protected function parseDocParams(
        string $docComment,
        string $httpMethod,
        array $pathParamNames = [],
    ): array {
        $params = [];

        if ($docComment === "") {
            return [];
        }

        preg_match_all(
            '/@param\s+(\S+)\s+\$([a-zA-Z_]\w*)\s*(.*)/',
            $docComment,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $m) {
            $rawType = $m[1];
            $name = $m[2];
            $tail = trim($m[3] ?? "");

            // 类型归一化
            $type = match (true) {
                str_contains($rawType, "int") => "int",
                str_contains($rawType, "bool") => "bool",
                str_contains($rawType, "float") => "float",
                str_contains($rawType, "array") => "array",
                default => "string",
            };

            // 默认值
            $default = null;
            if (preg_match("/\bdefault=([^\s]+)/", $tail, $dm)) {
                $default = $this->parsePhpValue($dm[1]);
            }

            // 是否必填
            $required = str_contains($tail, "required");

            // 参数来源
            if (preg_match("/\bsource=(path|query|body)\b/", $tail, $sm)) {
                $source = $sm[1];
            } else {
                // fallback：按 HTTP Method 推断
                $source = in_array($httpMethod, ["get", "delete"])
                    ? "query"
                    : "body";
            }

            // 路由占位符命中：强制走 path（优先级最高，覆盖 source=xxx 显式声明）
            if (in_array($name, $pathParamNames, true)) {
                $source = "path";
            }
            // 描述（把规则全部剥掉，剩下就是描述）
            $description = trim(
                preg_replace(
                    "/\b(required|optional|default=[^\s]+|source=(path|query|body))\b/",
                    "",
                    $tail,
                ),
            );

            $required = $required && $default === null;
            if ($source === "path") {
                $required = true;
            }
            $params[$source][$name] = [
                "type" => $type,
                "required" => $required,
                "default" => (string) $default === null,
                "desc" => $description,
            ];
        }

        return $params;
    }

    protected function parseField(string $raw, array $typeMap): array
    {
        if (str_contains($raw, "/")) {
            [$name, $suffix] = explode("/", $raw, 2);
            return [$name, $typeMap[$suffix] ?? "string"];
        }

        return [$raw, "string"];
    }

    protected function parsePhpValue(string $value)
    {
        $value = trim($value);

        if (is_numeric($value)) {
            return str_contains($value, ".") ? (float) $value : (int) $value;
        }

        if ($value === "true") {
            return true;
        }
        if ($value === "false") {
            return false;
        }
        if ($value === "null") {
            return null;
        }

        if (
            (str_starts_with($value, "'") && str_ends_with($value, "'")) ||
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    protected function stringifyForHtml(mixed $value): string
    {
        if ($value === null) {
            return "null";
        }

        if ($value === true) {
            return "true";
        }

        if ($value === false) {
            return "false";
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * 将 path 参数拼接到路由路径中
     *
     * @param string $path 原始路由路径
     * @param array $params 解析后的参数数组
     * @return string
     */
    protected function buildPathWithParams(string $path, array $params): string
    {
        // 先将 ThinkPHP 风格的占位符（:id 与 <id>）统一规范化为 OpenAPI 风格 {id}
        $path = $this->normalizePlaceholders("/" . ltrim($path, "/"));

        // 直接取 path 参数组
        $pathParams = $params["path"] ?? [];

        // 没有 path 参数，直接返回规范化后的原路径
        if (!$pathParams) {
            return $path;
        }

        $path = rtrim($path, "/");

        // 已存在的占位符，避免重复拼
        preg_match_all("/\{(\w+)\}/", $path, $exists);
        $exists = $exists[1] ?? [];

        foreach ($pathParams as $name => $meta) {
            // OpenAPI 规定：path 参数必须 required
            if (in_array($name, $exists, true)) {
                continue;
            }

            $path .= "/{" . $name . "}";
        }

        return $path;
    }

    /**
     * 把路由规则中的 `:name` / `<name>` 占位符统一规范化为 OpenAPI 风格的 `{name}`
     */
    protected function normalizePlaceholders(string $path): string
    {
        // ThinkPHP getRuleList 已把 :name 转成 <name>，但 include 直接读到的也可能是 :name
        $path =
            preg_replace("/:([A-Za-z_][A-Za-z0-9_]*)/", '{$1}', $path) ?? $path;
        $path =
            preg_replace("/<([A-Za-z_][A-Za-z0-9_]*)>/", '{$1}', $path) ??
            $path;
        return $path;
    }

    /**
     * 生成 HTML 格式文档
     */
    protected function generateHtml(array $docs): string
    {
        $appsJson = json_encode($docs["apps"], JSON_UNESCAPED_UNICODE);

        $html =
            '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' .
            $docs["title"] .
            '</title>
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

        .nav-app {
            border-bottom: 1px solid #34495e;
        }

        .nav-app-title {
            padding: 15px;
            font-size: 15px;
            font-weight: bold;
            background: #1a252f;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
        }

        .nav-app-title:hover {
            background: #2c3e50;
        }

        .nav-app-groups {
            background: #2c3e50;
        }

        .nav-app.collapsed .nav-app-groups {
            display: none;
        }

        .nav-group {
            border-left: 3px solid #3498db;
        }

        .nav-group-title {
            padding: 12px 15px 12px 20px;
            font-size: 14px;
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
            color: #2980b9;
            font-weight: bold;
        }

        .param-type {
            color: #e74c3c;
            margin-left: 10px;
            font-weight: 600;
        }

        .param-required {
            color: #c0392b;
            margin-left: 10px;
            font-weight: 600;
        }

        .param-default {
            color: #7f8c8d;
            margin-left: 10px;
            font-style: italic;
        }

        .param-desc {
            color: #a9adae;
            margin-left: 10px;
            font-style: italic;
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
            <h1>' .
            $docs["title"] .
            '</h1>
            <p>版本: ' .
            $docs["version"] .
            '</p>
            <p>' .
            $docs["base_url"] .
            '</p>
        </div>
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="搜索接口...">
        </div>
        <div class="nav-menu" id="navMenu">';

        // 按应用分组展示
        foreach ($docs["apps"] as $appName => $app) {
            $html .=
                '<div class="nav-app">
                <div class="nav-app-title">' .
                $app["name"] .
                ' <span class="toggle-icon">▼</span></div>
                <div class="nav-app-groups">';

            // 按路由分组展示
            foreach ($app["groups"] as $groupName => $group) {
                $html .=
                    '<div class="nav-group">
                    <div class="nav-group-title">' .
                    $groupName .
                    ' <span class="toggle-icon">▼</span></div>
                    <div class="nav-routes">';

                foreach ($group["routes"] as $route) {
                    $routeId =
                        "route-" .
                        md5($groupName . $route["path"] . $route["method"]);
                    $html .=
                        '<div class="nav-route"
                    data-route-id="' .
                        $routeId .
                        '"
                    data-path="' .
                        htmlspecialchars($route["path"]) .
                        '"
                    data-method="' .
                        $route["method"] .
                        '">
                    <span class="nav-route-method ' .
                        $route["method"] .
                        '">' .
                        $route["method"] .
                        '</span>
                    <span class="nav-route-alias">' .
                        htmlspecialchars($route["alias"]) .
                        '</span>
                </div>';
                }

                $html .= "</div></div>"; // 关闭 nav-routes 和 nav-group
            }

            $html .= "</div></div>"; // 关闭 nav-app-groups 和 nav-app
        }

        $html .= "</div>"; // 关闭 nav-menu
        $html .= "</div>"; // 关闭 sidebar

        $html .=
            '<div class="main-content">
        <div class="content-wrapper">
            <div class="header">
                <h1>' .
            $docs["title"] .
            '</h1>
                <p>' .
            $docs["description"] .
            '</p>
                <p>基础URL: ' .
            $docs["base_url"] .
            '</p>
            </div>';

        foreach ($docs["apps"] as $appName => $app) {
            $html .=
                '<div class="app-section" id="app-' .
                md5($appName) .
                '">
                <h2 class="app-title">' .
                $app["name"] .
                "</h2>";

            foreach ($app["groups"] as $groupName => $group) {
                $html .=
                    '<div class="group-section" id="group-' .
                    md5($groupName) .
                    '">
                    <h3 class="group-title">' .
                    $groupName .
                    "</h3>";

                foreach ($group["routes"] as $route) {
                    $routeId =
                        "route-" .
                        md5($groupName . $route["path"] . $route["method"]);
                    $html .=
                        '<div class="route-card" id="' .
                        $routeId .
                        '">
                    <div class="route-header">
                        <span class="route-method ' .
                        $route["method"] .
                        '">' .
                        $route["method"] .
                        '</span>
                        <span class="route-path">' .
                        htmlspecialchars($route["path"]) .
                        '</span>
                        <span class="route-title">' .
                        htmlspecialchars($route["alias"]) .
                        '</span>
                    </div>
                    <p class="route-description">' .
                        htmlspecialchars($route["description"]) .
                        "</p>";

                    if (!empty($route["params"])) {
                        foreach ($route["params"] as $source => $params) {
                            $html .=
                                '<div class="section-title">请求参数(' .
                                $this->sourceNameMap[$source] .
                                ')</div>
                                          <div class="params-block">';
                            foreach ($params as $param => $paramData) {
                                $html .=
                                    '<div class="param-item">
                                            <span class="param-name">' .
                                    htmlspecialchars($param) .
                                    '</span>
                                            <span class="param-type">[' .
                                    htmlspecialchars($paramData["type"]) .
                                    ']</span>
                                            <span class="param-required">是否必填:' .
                                    htmlspecialchars(
                                        $paramData["required"] ? "是" : "否",
                                    ) .
                                    '</span>
                                            <span class="param-default">默认值:' .
                                    htmlspecialchars(
                                        $this->stringifyForHtml(
                                            $paramData["default"],
                                        ),
                                    ) .
                                    "</span>";

                                // 只有 desc 非空才输出
                                if ($paramData["desc"] !== "") {
                                    $html .=
                                        '<span class="param-desc">描述：' .
                                        htmlspecialchars($paramData["desc"]) .
                                        "</span>";
                                }
                                $html .= "</div>";
                            }
                            $html .= "</div>";
                        }
                    }

                    if (!empty($route["response"])) {
                        $html .=
                            '<div class="section-title">响应数据</div>
                        <div class="response-block">' .
                            json_encode(
                                $route["response"],
                                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
                            ) .
                            "</div>";
                    }

                    $html .= "</div>";
                }

                $html .= "</div>"; // 关闭 group-section
            }

            $html .= "</div>"; // 关闭 app-section
        }

        $html .= '</div>

    <script>
        const navApps = document.querySelectorAll(".nav-app");
        const navGroups = document.querySelectorAll(".nav-group");
        const navRoutes = document.querySelectorAll(".nav-route");
        const searchInput = document.getElementById("searchInput");

        // 应用折叠/展开
        navApps.forEach(app => {
            const title = app.querySelector(".nav-app-title");
            title.addEventListener("click", () => {
                app.classList.toggle("collapsed");
            });
        });

        // 子分组折叠/展开
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
                navApps.forEach(app => {
                    const visibleRoutes = app.querySelectorAll(".nav-route[style=\'display: block\']");
                    if (visibleRoutes.length > 0) {
                        app.classList.remove("collapsed");
                        // 展开子分组
                        const groups = app.querySelectorAll(".nav-group");
                        groups.forEach(group => {
                            const groupVisibleRoutes = group.querySelectorAll(".nav-route[style=\'display: block\']");
                            if (groupVisibleRoutes.length > 0) {
                                group.classList.remove("collapsed");
                            }
                        });
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
     */
    protected function generateOpenApi(array $docs): string
    {
        $openapi = [
            "openapi" => "3.0.0",
            "info" => [
                "title" => $docs["title"] ?? "",
                "description" => $docs["description"] ?? "",
                "version" => $docs["version"] ?? "1.0.0",
            ],
            "servers" => [
                [
                    "url" => $docs["base_url"] ?? "",
                    "description" => "开发环境",
                ],
            ],
            "paths" => [],
            "tags" => [],
        ];

        foreach ($docs["apps"] ?? [] as $appName => $app) {
            foreach ($app["groups"] ?? [] as $groupName => $group) {
                // 兼容 namutes / routes 写错的问题
                $routes = $group["routes"] ?? ($group["namutes"] ?? []);

                $tagName = $app["name"] . "/" . $groupName;

                $openapi["tags"][$tagName] = [
                    "name" => $tagName,
                    "description" => "{$app["name"]} - {$groupName}",
                ];

                foreach ($routes as $route) {
                    if (empty($route["method"]) || empty($route["path"])) {
                        continue;
                    }

                    $method = strtolower($route["method"]);
                    $path = "/" . ltrim($route["path"], "/");

                    $operation = [
                        "summary" => $route["alias"] ?? "",
                        "description" => $route["description"] ?? "",
                        "tags" => [$tagName],
                    ];

                    $params = $route["params"] ?? [];

                    // 防御：params 不是数组直接跳过
                    if (!is_array($params)) {
                        $params = [];
                    }

                    $queryParams = [];
                    $bodyProps = [];
                    $bodyRequired = [];

                    foreach ($params as $source => $sourceParams) {
                        if (!is_array($sourceParams)) {
                            continue;
                        }
                        foreach ($sourceParams as $name => $meta) {
                            if (!is_array($meta)) {
                                continue;
                            }
                            $type = $this->mapType($meta["type"] ?? "string");
                            $required = (bool) ($meta["required"] ?? false);
                            $desc = (string) ($meta["desc"] ?? "");
                            $default = $meta["default"] ?? null;

                            switch ($source) {
                                case "query":
                                    $queryParam = [
                                        "name" => $name,
                                        "in" => "query",
                                        "required" => $required,
                                        "description" => $desc,
                                        "schema" => [
                                            "type" => $type,
                                        ],
                                    ];

                                    if (
                                        $default !== null &&
                                        $default !== "null"
                                    ) {
                                        $queryParam["schema"][
                                            "default"
                                        ] = $default;
                                    }
                                    $queryParams[] = $queryParam;
                                    break;
                                case "body":
                                    $bodyProps[$name] = [
                                        "type" => $type,
                                        "description" => $desc,
                                    ];
                                    if (
                                        $default !== null &&
                                        $default !== "null"
                                    ) {
                                        $bodyProps[$name]["default"] = $default;
                                    }
                                    if ($required) {
                                        $bodyRequired[] = $name;
                                    }
                                    break;
                                case "path":
                                    $queryParams[] = [
                                        "name" => $name,
                                        "in" => "path",
                                        "required" => true, // path 参数 OpenAPI 规定必须 true
                                        "description" => $desc,
                                        "schema" => [
                                            "type" => $type,
                                        ],
                                    ];
                                    break;
                            }
                        }
                    }
                    if ($queryParams) {
                        $operation["parameters"] = $queryParams;
                    }

                    if ($bodyProps) {
                        $schema = [
                            "type" => "object",
                            "properties" => $bodyProps,
                        ];
                        if ($bodyRequired) {
                            $schema["required"] = $bodyRequired;
                        }

                        $operation["requestBody"] = [
                            "required" => true,
                            "content" => [
                                "multipart/form-data" => [
                                    "schema" => $schema,
                                ],
                            ],
                        ];
                    }

                    $operation["responses"] = [
                        "200" => [
                            "description" => "成功",
                            "content" => [
                                "application/json" => [
                                    "schema" => [
                                        "type" => "object",
                                        "properties" => [
                                            "code" => [
                                                "type" => "integer",
                                                "description" => "状态码",
                                            ],
                                            "message" => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        "400" => [
                            "description" => "失败",
                            "content" => [
                                "application/json" => [
                                    "schema" => [
                                        "type" => "object",
                                        "properties" => [
                                            "code" => [
                                                "type" => "integer",
                                                "description" => "状态码",
                                            ],
                                            "message" => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];

                    $openapi["paths"][$path][$method] = $operation;
                }
            }
        }

        // tags 去重并转为数组
        $openapi["tags"] = array_values($openapi["tags"]);

        return json_encode(
            $openapi,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    /**
     * 映射 PHP 类型到 OpenAPI 类型
     */
    protected function mapType(string $phpType): string
    {
        $typeMap = [
            "int" => "integer",
            "integer" => "integer",
            "float" => "number",
            "double" => "number",
            "bool" => "boolean",
            "boolean" => "boolean",
            "string" => "string",
            "array" => "array",
            "object" => "object",
        ];

        return $typeMap[strtolower($phpType)] ?? "string";
    }
}
