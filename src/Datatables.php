<?php

namespace App\Libraries;

use AndikAryanto11\Exception\DtTablesException;use Exception;

class DatatablesLib
{
    protected $request;
    protected $filter     = false;
    protected $useIndex   = true;
    protected $isEloquent = false;
    protected $eloquent;
    protected $table;
    protected $dtRowClass;
    protected $dtRowId;
    protected $columnCounter  = 0;
    protected $column         = [];
    protected $dtTableColumns = [];
    protected $returnEntity   = false;
    protected $modelNameSpace = 'App\Eloquents';
    protected $currentPage    = null;
    protected $pageSize       = null;

    protected $output = [
        'draw'            => null,
        'recordsTotal'    => null,
        'recordsFiltered' => null,
        'data'            => null,
    ];

    public function __construct($filter = [], $returnEntity = true, $useIndex = true, $modelNameSpace = 'App\Eloquents')
    {
        $this->request = \Config\Services::request();

        $this->modelNameSpace = $modelNameSpace;
        if (!empty($filter)) {
            $this->filter = $filter;
        }

        if (!$useIndex) {
            $this->useIndex = false;
        } else {
            if (!is_numeric($this->request->getGet('columns')[0]['data'])) {
                $this->dtTableColumns = $this->request->getGet('columns');
                $this->useIndex       = false;
            } else {
                $this->useIndex = true;
            }
        }

        $this->returnEntity = $returnEntity;
    }

    /**
     * Eloquent Class you want to search for the data
     *
     * @param string $eloquentNameSpace Eloquenst class name
     */
    public function eloquent(string $eloquentNameSpace)
    {
        $this->eloquent   = $this->modelNameSpace . '\\' . $eloquentNameSpace;
        $this->isEloquent = $this->returnEntity ? true : false;
        return $this;
    }

    /**
     * Set post parameter to eloquent fetch function parameter
     */
    public function setParams()
    {
        $params               = [];
        $params['join']       = isset($this->filter['join']) ? $this->filter['join'] : null;
        $params['where']      = isset($this->filter['where']) ? $this->filter['where'] : null;
        $params['whereIn']    = isset($this->filter['whereIn']) ? $this->filter['whereIn'] : null;
        $params['orWhereIn']  = isset($this->filter['orWhereIn']) ? $this->filter['orWhereIn'] : null;
        $params['orWhere']    = isset($this->filter['orWhere']) ? $this->filter['orWhere'] : null;
        $params['whereNotIn'] = isset($this->filter['whereNotIn']) ? $this->filter['whereNotIn'] : null;
        $params['like']       = isset($this->filter['like']) ? $this->filter['like'] : null;
        $params['orLike']     = isset($this->filter['orLike']) ? $this->filter['orLike'] : null;
        $params['group']      = isset($this->filter['group']) ? $this->filter['group'] : null;

        if ($this->request->getGet('length') !== -1) {
            $this->currentPage = $this->request->getGet('start') / $this->request->getGet('length') + 1;
            $this->pageSize    = (int)$this->request->getGet('length');
            $params['limit']   = [
                'page' => $this->currentPage,
                'size' => $this->pageSize,
            ];
        }

        if ($this->request->getGet('search') && $this->request->getGet('search')['value'] !== '') {
            $searchValue = $this->request->getGet('search')['value'];

            foreach ($this->column as $column) {
                if (!empty($column['column'])) {
                    $strparam = 'orLike';

                    if ($column['searchable']) {
                        $col = explode('.', $column['column']);
                        if (count($col) === 3) {
                            $params['group'][$strparam][$col[0] . '.' . $col[1]] = $searchValue;
                        } else if (count($col) === 2) {
                            $params['group'][$strparam][$col[0] . '.' . $col[1]] = $searchValue;
                        } else {
                            $params['group'][$strparam][$column['column']] = $searchValue;
                        }
                    }
                }
            }
        }

        if ($this->request->getGet('order') && count($this->request->getGet('order'))) {
            $order = $this->request->getGet('order')[0];

            if (isset($this->column[$order['column']]) && $this->column[$order['column']]['orderable']) {
                $col          = explode('.', $this->column[$order['column']]['column']);
                $actualColumn = '';
                if (count($col) === 3) {
                    $actualColumn = $col[0] . '.' . $col[1];
                } else if (count($col) === 2) {
                    $actualColumn = $col[0] . '.' . $col[1];
                } else {
                    $actualColumn = $col[0];
                }

                $params['order'] = [
                    $actualColumn => $order['dir'] === 'asc' ? 'ASC' : 'DESC',
                ];
            }
        }
        return $params;
    }

    /**
     * Populate the data to store to datatables.net
     */

    public function populate()
    {
        try {
            $params = $this->setParams();
            $result = $this->eloquent::findAll($params, $this->returnEntity, $this->getColumnsOnly());

            $this->output['draw']            = !empty($this->request->getGet('draw')) ? intval($this->request->getGet('draw')) : 0;
            $this->output['recordsTotal']    = intval(count($result));
            $this->output['recordsFiltered'] = intval($this->allData($params));
            $this->output['data']            = $this->output($result);
        } catch (Exception $e) {
            $this->output['error'] = $e->getMessage();
        }

        return $this->output;
    }

    /**
     * Count All data in Eloquent table mapping
     *
     * @param array $filter
     */
    private function allData($filter = [])
    {
        $params = [
            'join'       => isset($filter['join']) ? $filter['join'] : null,
            'where'      => isset($filter['where']) ? $filter['where'] : null,
            'whereIn'    => isset($filter['whereIn']) ? $filter['whereIn'] : null,
            'orWhere'    => isset($filter['orWhere']) ? $filter['orWhere'] : null,
            'whereNotIn' => isset($filter['whereNotIn']) ? $filter['whereNotIn'] : null,
            'like'       => isset($filter['like']) ? $filter['like'] : null,
            'orLike'     => isset($filter['orLike']) ? $filter['orLike'] : null,
            'group'      => isset($filter['group']) ? $filter['group'] : null,
        ];
        return $this->eloquent::count($params, $this->returnEntity, $this->getColumnsOnly());
    }

    /**
     * Set Column You want to return
     *
     * @param string  $column
     * @param string  $foreignKey     Nullable
     * @param Closure $callback       Nullable
     * @param boolean $searchable     Nullable
     * @param boolean $orderable      Nullable
     * @param boolean $isdefaultorder Nullable
     */
    public function addColumn($column, $foreignKey = null, $callback = null, $searchable = true, $orderable = true, $isdefaultorder = false)
    {
        $columns = [
            'column'         => $column,
            'foreignKey'     => $foreignKey,
            'callback'       => $callback,
            'searchable'     => $searchable,
            'orderable'      => $orderable,
            'isdefaultorder' => $isdefaultorder,
        ];
        array_push($this->column, $columns);
        $this->columnCounter++;
        return $this;
    }

    /**
     * Get the data
     *
     * @param array $data Of Eloquent Object
     */
    private function output($datas)
    {
        $out = [];
        $i   = ($this->currentPage * $this->pageSize) - $this->pageSize;
        foreach ($datas as $data) {
            $row = [];
            foreach ($this->column as $column) {
                $rowdata = null;

                if (!is_null($column['callback'])) {
                    $fn      = $column['callback'];
                    $rowdata = $fn($data, $i);
                } else {
                    $rowdata = $this->getColValue($column, $data);
                }

                if ($this->useIndex) {
                    $row[] = $rowdata;
                } else {
                    $selectedColumn = '';
                    $col            = explode('.', $column['column']);
                    if (count($col) === 3) {
                        $selectedColumn = $col[2];
                    } else if (count($col) === 2) {
                        $selectedColumn = $col[1];
                    } else {
                        $selectedColumn = $col[0];
                    }
                    $row[$selectedColumn] = $rowdata;
                }

                if ($this->dtRowId && $this->dtRowId === $column['column']) {
                    $col = explode('.', $column['column']);
                    if (count($col) === 3) {
                        $selectedColumn = $col[2];
                    } else if (count($col) === 2) {
                        $selectedColumn = $col[1];
                    } else {
                        $selectedColumn = $col[0];
                    }
                    $row['DT_RowId'] = $data->$selectedColumn;
                }

                $row['DT_RowClass'] = $this->dtRowClass;
            }
            $i++;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Add row class name for datatable.net
     */

    public function addDtRowClass($className)
    {
        $this->dtRowClass = $className;
        return $this;
    }

    /**
     * Add row id name for datatable.net
     */
    public function addDtRowId($columName)
    {
        $this->dtRowId = $columName;
        return $this;
    }

    /**
     * Get value if foreignkey filed not empty otherwise will return from Closure
     *
     * @param string $column
     * @param object $data   Of intended Instace Eloquent
     */
    private function getColValue($column, $data)
    {
        if ($this->returnEntity) {
            $nameSpace = explode('\\', $this->eloquent);

            if (!is_null($column['column'])) {
                $col = explode('.', $column['column']);
                if (count($col) === 3) {
                    $newobj = new $this->eloquent;
                    if ($newobj->getTableName() !== $col[0]) {
                        $selectedColumn = $col[1];
                        return $data->hasOneOrNew($nameSpace[0] . '\\' . $nameSpace[1] . '\\' . $col[0], $column['foreignKey'])->$selectedColumn;
                    } else {
                        $selectedColumn = $col[1];
                        return $data->$selectedColumn;
                    }
                } else if (count($col) === 2) {
                    $selectedColumn = $col[1];
                    return $data->$selectedColumn;
                } else {
                    $selectedColumn = $col[0];
                    return $data->$selectedColumn;
                }
            }
        } else {
            $col        = explode('.', $column['column']);
            $columnname = null;
            if (count($col) === 3) {
                $columnname = $col[2];
                return $data->$columnname;
            } else if (count($col) === 2) {
                $columnname = $col[1];
                return $data->$columnname;
            } else {
                $columnname = $column['column'];
                return $data->$columnname;
            }
        }
        return null;
    }

    /**
     * Not Used Yet
     */
    public function getColumns()
    {
        return $this->column;
    }

    /**
     * Collect all columns to select in database query
     */
    private function getColumnsOnly()
    {
        $columns = [];
        foreach ($this->column as $column) {
            if (!empty($column['column'])) {
                $col = explode('.', $column['column']);
                if (count($col) === 3) {
                    $columns[] = $col[0] . '.' . $col[1] . ' ' . $col[2];
                } else {
                    $columns[] = $column['column'];
                }
            }
        }
        return $columns;
    }
}
