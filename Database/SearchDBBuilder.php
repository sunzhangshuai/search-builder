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

namespace App\SearchBuilders\Database;

use Illuminate\Foundation\Application;

/**
 * SearchDBBuilder : DB基础查询模块
 *
 * @category SearchDBBuilder
 * @author   zhangshuai <zhangshaui1134@gmail.com>
 */
abstract class SearchDBBuilder
{
    /**
     * @var Object 数据模型
     */
    protected $model;

    /**
     * @var Application|mixed
     */
    protected $query;

    protected $filters;

    protected $page;

    protected $size;

    protected $sort;

    protected $include;

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
        '>=' => '>=',
        '<=' => '<=',
        '>'  => '>',
        '<'  => '<',
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
     * 特殊匹配
     *
     * @return mixed
     */
    abstract protected function getSpecialFilters();

    /**
     * 获取包含数据
     *
     * @return array
     */
    abstract protected function getIncludes();

    /**
     * SearchESBuilder constructor.
     */
    public function __construct()
    {
        $this->query = $this->model->query();
    }

    /**
     * @param array $filters
     * @param int   $page
     * @param int   $size
     * @param array $sort
     * @param array $include
     *
     * @return mixed
     */
    public function search($filters = [], $page = 0, $size = 0, $sort = [], $include = [])
    {
        $this->filters = $filters;
        $this->page    = $page;
        $this->size    = $size;
        $this->sort    = $sort;
        $this->include = $include;
        return $this->builder()->get();
    }

    /**
     * @return SearchDBBuilder
     */
    public function builder()
    {
        return $this->filter()->sort()->paginate()->include();
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
            ->filterSpecialFilters();
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
            if (!$filter_value) return;
            if (is_array($filter_value)) {
                $this->query->whereIn($filter, $filter_value);
                return;
            }
            $this->query->where($filter, $filter_value);
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
            if (!$filter_value) return;
            if (strpos($filter, 'not_') === 0) {
                $filter = substr($filter, 4);
            }
            if (is_array($filter_value)) {
                $this->query->whereNotIn($filter, $filter_value);
                return;
            }
            $this->query->whereNot($filter, $filter_value);
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
            $filter_value = array_get($this->filters, $filter);
            if (!$filter_value) return;
            if (strpos($filter, 'range_') === 0) {
                $filter = substr($filter, 6);
            }
            $filter_value = is_array($filter_value) ? $filter_value : [$filter_value];
            foreach ($filter_value as $value) {
                foreach ($this->range_map as $symbol => $range_key)
                    if (strpos($value, $symbol) === 0) {
                        $value = ltrim($value, $symbol);
                        $this->query->where($filter, $range_key, $value);
                        break;
                    }
            }
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
            if (!$filter_value) return;
            if (strpos($filter, 'contain_') === 0) {
                $filter = ltrim($filter, 'contain_');
            }
            $this->query->where($filter, 'like', '%' . $filter_value . '%');
        });
        return $this;
    }

    /**
     * 特殊查询匹配
     *
     * @return $this
     */
    protected function filterSpecialFilters()
    {
        collect($this->getSpecialFilters())->filter(function ($filter) {
            $filter_value = array_get($this->filters, $filter);
            $method = camelize($filter) . 'Where';
            $this->$method($this->query, $filter_value);
        });
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
            $this->query->orderBy($key, $order);
        }
        return $this;
    }

    /**
     * 分页
     *
     * @return SearchDBBuilder
     */
    protected function paginate()
    {
        if ($this->page && $this->size) {
            $offset = ($this->page - 1) * $this->size;
            $this->query->offset($offset)->limit($this->size);
        }
        return $this;
    }

    /**
     * @return $this
     */
    public function include()
    {
        foreach ($this->include as $key => $include) {
            if (!in_array($include, $this->getIncludes())) {
                unset($this->includes[$key]);
            }
        }
        if (!empty($this->include)) {
            $this->query->with($this->include);
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
        if ($this->page && $this->size) {
            $data = $this->query->paginate($this->size)->toArray();
            $list = array_get($data, 'data', []);
            $mate = [
                'total'      => array_get($data, 'total', 0),
                'size'       => array_get($data, 'per_page', 0),
                'page'       => array_get($data, 'current_page', 0),
                'total_page' => array_get($data, 'last_page', 0),
            ];
        } else {
            $list = $this->query->get()->toArray();
            $mate = [
                'total'      => count($list),
                'size'       => count($list),
                'page'       => 1,
                'total_page' => 1,
            ];
        }

        return [
            'list' => $list,
            'mate' => $mate
        ];
    }
}
