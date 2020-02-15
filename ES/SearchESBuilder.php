<?php
/**
 * SearchESBuilder.php :
 *
 * PHP version 7.1
 *
 * @category SearchESBuilder
 * @package  App\SearchBuilders\ES
 * @author   zhangshuai <zhangshaui1134@gmail.com>
 */

namespace App\SearchBuilders\ES;

use Exception;
use Illuminate\Foundation\Application;

/**
 * SearchESBuilder : es基础查询模块
 *
 * @category SearchESBuilder
 * @author   zhangshuai <zhangshaui1134@gmail.com>
 */
abstract class SearchESBuilder
{
    /**
     * @var Application|mixed
     */
    protected $client;

    protected $filters;

    protected $page;

    protected $size;

    protected $sort;

    /**
     * 获取索引
     *
     * @return string
     */
    abstract protected function getIndex();

    /**
     * 获取类型
     *
     * @return string
     */
    abstract protected function getType();

    /**
     * @var array 查询项
     */
    protected $body = [];

    /**
     * @var array 查询参数
     */
    protected $params = [];

    /**
     * @var array 范围的配置
     */
    protected $range_map = [
        '>=' => 'gte',
        '<=' => 'lte',
        '>'  => 'gt',
        '<'  => 'lt',
    ];

    /**
     * 普通过滤项
     * must 只支持 term 和 terms 匹配
     *
     * @return array
     */
    abstract protected function getNormalFilters();

    /**
     * 非项过滤
     * must_not, 一般格式为 not_ + 字段名
     * eg: not_grade => [2, 3]
     *
     *
     * @return array
     */
    abstract protected function getNotFilters();

    /**
     * 范围过滤
     * range: 一般格式为 range_ + 字段名
     * eg: range_day => ['>2019-06-01', '<2019-07-01']
     *
     * @return array
     */
    abstract protected function getRangeFilters();

    /**
     * 模糊匹配
     * match: 一般格式为 contain_ + 字段名
     * eg: contain_a_real_name => '张'
     *
     * @return array
     */
    abstract protected function getContainFilters();

    /**
     * SearchESBuilder constructor.
     */
    public function __construct()
    {
        $this->client = app('es');
        $this->initParams();
    }

    /**
     * 初始化查询参数
     */
    protected function initParams()
    {
        $this->params = [
            'index' => $this->getIndex(),
            'type'  => $this->getType(),
            'body'  => [],
        ];
    }

    /**
     * 查询
     *                                     _
     *  ___    ___    __ _   _ __    ___  | |__
     * / __|  / _ \  / _` | | '__|  / __| | '_ \
     * \__ \ |  __/ | (_| | | |    | (__  | | | |
     * |___/  \___|  \__,_| |_|     \___| |_| |_|
     *
     * @param array $filters 要过滤的字段
     *                       如 [
     *                       'course_id'           => [111, 222],
     *                       'not_grade'           => [2, 3],
     *                       'range_day'           => ['>2019-06-01', '<2019-07-01'],
     *                       'contain_a_real_name' => '张'
     *                       ]
     * @param int   $page    页数
     * @param int   $size    每页条数
     * @param array $sort
     * @param array $aggs    要聚合的条件
     *                       $aggs 可包含3个key
     *                       'groups'  要进行分组的字段(可选)，一维数组，不需要指定key
     *                       'range'   要进行分片的字段,（可选），一维数组，需要指定key->value，其中key为分片字段，value为废片维度
     *                       'aggs'    要聚合计算的字段（可选）,一维数组，需要指定key->value，其中key为聚合运算的别名，value为聚合的字段
     *                       例如 $aggs = [
     *                       'groups' => ['server_ip','server_name'],
     *                       'range'  => ['collTime' =>'hour'],
     *                       'aggs'   => ['cpu_user' => 'cpu.user'],
     *                       ]
     *
     * @return array
     * @throws Exception
     */
    public function search($filters = [], $page = 1, $size = 20, $sort = [], $aggs = [])
    {
        $this->body    = [];
        $this->filters = $filters;
        $this->page    = $page;
        $this->size    = $size;
        $this->sort    = $sort;
        return $this->builder()->get();
    }

    /**
     * 获取ES查询语句  组合查询语句 按照聚合或者过滤及统计等方式
     *  _               _   _       _
     * | |__    _   _  (_) | |   __| |   ___   _ __
     * | '_ \  | | | | | | | |  / _` |  / _ \ | '__|
     * | |_) | | |_| | | | | | | (_| | |  __/ | |
     * |_.__/   \__,_| |_| |_|  \__,_|  \___| |_|
     *
     *
     *
     * @return SearchESBuilder
     * @throws Exception
     */
    public function builder()
    {
        return $this->source()->filter()->sort()->paginate();
    }

    /**
     * 查询
     *
     * @return $this
     */
    public function source()
    {
        if (isset($this->filters['_source'])) {
            $this->body['_source'] = $this->filters['_source'];
        }
        return $this;
    }

    /**
     * 过滤
     *
     * @return $this
     */
    public function filter()
    {
        return $this->filterNormal()
            ->filterRange()
            ->filterNot()
            ->filterContain()
            ->filterExists();
    }

    /**
     * 普通项过滤
     *
     * @return $this
     */
    protected function filterNormal()
    {
        collect($this->getNormalFilters())->filter(function ($filter) {
            $filter_value = array_get($this->filters, $filter);
            if ($filter_value === null || $filter_value === '') return;
            if (is_array($filter_value) && count($filter_value) == 0) return;
            if (is_array($filter_value)) {
                $this->body['query']['bool']['filter']['bool']['must'][]['terms'][$filter] = $filter_value;
                return;
            }
            $this->body['query']['bool']['filter']['bool']['must'][]['term'][$filter] = $filter_value;
        });
        return $this;
    }

    /**
     * 非项过滤
     *
     * @return $this
     */
    protected function filterNot()
    {
        collect($this->getNotFilters())->filter(function ($filter) {
            $filter_value = array_get($this->filters, $filter);
            if ($filter_value === null || $filter_value === '') return;
            if (is_array($filter_value) && count($filter_value) == 0) return;
            if (strpos($filter, 'not_') === 0) {
                $filter = substr($filter, 4);
            }
            if (is_array($filter_value)) {
                $this->body['query']['bool']['filter']['bool']['must_not'][]['terms'][$filter] = $filter_value;
                return;
            }
            $this->body['query']['bool']['filter']['bool']['must_not'][]['term'][$filter] = $filter_value;
        });
        return $this;
    }

    /**
     * 范围过滤
     *
     * @return $this
     */
    protected function filterRange()
    {
        collect($this->getRangeFilters())->filter(function ($filter) {
            $range        = [];
            $filter_value = array_get($this->filters, $filter);
            if ($filter_value === null || $filter_value === '') return;
            if (strpos($filter, 'range_') === 0) {
                $filter = substr($filter, 6);
            }
            $filter_value = is_array($filter_value) ? $filter_value : [$filter_value];
            foreach ($filter_value as $value) {
                foreach ($this->range_map as $symbol => $range_key)
                    if (strpos($value, $symbol) === 0) {
                        $value             = ltrim($value, $symbol);
                        $range[$range_key] = $value;
                        break;
                    }
            }
            $this->body['query']['bool']['filter']['bool']['must'][]['range'][$filter] = $range;
        });
        return $this;
    }

    /**
     * 模糊匹配
     *
     * @return $this
     */
    protected function filterContain()
    {
        collect($this->getContainFilters())->filter(function ($filter) {
            $filter_value = array_get($this->filters, $filter);
            if ($filter_value === null || $filter_value === '') return;
            if (strpos($filter, 'contain_') === 0) {
                $filter = ltrim($filter, 'contain_');
            }
            $this->body['query']['bool']['filter']['bool']['must'][]['match'][$filter] = $filter_value;
        });
        return $this;
    }

    /**
     * 存在匹配
     *
     * @return $this
     */
    protected function filterExists()
    {
        $exist_fields = array_get($this->filters, 'exist_field', []);
        if ($exist_fields) {
            foreach ($exist_fields as $exist_field) {
                $this->body['query']['bool']['must']['bool']['must'][]['exists']['field'] = $exist_field;
            }
        }
        return $this;
    }

    /**
     * 排序
     *
     * @return $this
     */
    protected function sort()
    {
        foreach ($this->sort as $key => $order) {
            $this->body['sort'][$key]['order'] = $order;
        }
        return $this;
    }

    /**
     * 分页
     *
     * @return SearchESBuilder
     * @throws Exception
     */
    protected function paginate()
    {
        if ($this->page && $this->size) {
            $from = ($this->page - 1) * $this->size;
            if ($from > 10000) {
                throw new Exception('所选页数过大，暂不支持');
            }
            $this->body['size'] = $this->size;
            $this->body['from'] = $from;
        } else {
            $this->body['size'] = 10000;
            $this->body['from'] = 0;
        }
        return $this;
    }

    /**
     * 获取数据
     *
     * @return array
     */
    protected function get()
    {
        $this->params['body'] = $this->body;
        $es_result            = $this->client->search($this->params);
        $list                 = collect(array_get($es_result, 'hits.hits', []))->map(function ($item) {
            return array_get($item, '_source');
        })->all();
        $size = !empty($this->size) ? $this->size : count($list);
        $mate                 = [
            'total'      => array_get($es_result, 'hits.total', 0),
            'size'       => $size,
            'page'       => $this->page,
            'total_page' => (int)ceil(array_get($es_result, 'hits.total', 0) / ($size ? $size : 1)),
        ];
        return [
            'list' => $list,
            'mate' => $mate
        ];
    }
}
