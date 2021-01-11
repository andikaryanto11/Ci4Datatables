<?php

namespace AndikAryanto11;

use AndikAryanto11\Exception\DtTablesException;

use Exception;

class Datatables
{

    protected $request;
    protected $filter = false;
    protected $useIndex = true;
    protected $isEloquent = false;
    protected $eloquent;
    protected $table;
    protected $dtRowClass;
    protected $dtRowId;
    protected $columnCounter = 0;
    protected $column = array();
    protected $dtTableColumns = array();
    protected $returnEntity = false;

    protected $output = array(
        "draw" => null,
        "recordsTotal" => null,
        "recordsFiltered" => null,
        "data" => null
    );

    public function __construct($filter = array(), $returnEntity = true, $useIndex = true)
    {
        $this->request = \Config\Services::request();
        if (!empty($filter))
            $this->filter = $filter;

        if (!$useIndex) {
            $this->useIndex = false;
        } else {
            if (!is_numeric($this->request->getGet('columns')[0]['data'])) {
                $this->dtTableColumns = $this->request->getGet('columns');
                $this->useIndex = false;
            } else {
                $this->useIndex = true;
            }
        }

        $this->returnEntity = $returnEntity;
    }

    public function eloquent(string $eloquentNameSpace)
    {
        // if(is_subclass_of($eloquentNameSpace, "AndikAryanto11\Eloquent")){
        $this->eloquent = $eloquentNameSpace;
        $this->isEloquent = $this->returnEntity ? true : false;
        return $this;
        // }

        // throw new DtTablesException($eloquentNameSpace. " Is Not Instance of AndikAryanto11\Eloquent");
    }

    public function setParams()
    {
        $params = array();
        $params['join'] = isset($this->filter['join']) ? $this->filter['join'] : null;
        $params['where'] = isset($this->filter['where']) ? $this->filter['where'] : null;
        $params['whereIn'] = isset($this->filter['whereIn']) ? $this->filter['whereIn'] : null;
        $params['orWhereIn'] = isset($this->filter['orWhereIn']) ? $this->filter['orWhereIn'] : null;
        $params['orWhere'] = isset($this->filter['orWhere']) ? $this->filter['orWhere'] : null;
        $params['whereNotIn'] = isset($this->filter['whereNotIn']) ? $this->filter['whereNotIn'] : null;
        $params['like'] = isset($this->filter['like']) ? $this->filter['like'] : null;
        $params['orLike'] = isset($this->filter['orLike']) ? $this->filter['orLike'] : null;
        $params['group'] = isset($this->filter['group']) ? $this->filter['group'] : null;

        if ($this->request->getGet('length') != -1) {
            $params['limit'] = array(
                'page' => $this->request->getGet('start') / $this->request->getGet('length') + 1,
                'size' => (int)$this->request->getGet('length')
            );
        }

        if ($this->request->getGet('search') && $this->request->getGet('search')['value'] != '') {
            $searchValue = $this->request->getGet('search')['value'];

            foreach ($this->column as $column) {
                if (!empty($column['column'])) {
                    $strparam = "orLike";

                    if ($column['searchable']) {
                        $col = explode(".", $column['column']);
                        if (count($col) == 3)
                            $params["group"][$strparam][$col[0] . "." . $col[1]] =  $searchValue;
                        else if (count($col) == 2)
                            $params["group"][$strparam][$col[0] . "." . $col[1]] =  $searchValue;
                        else
                            $params["group"][$strparam][$column['column']] = $searchValue;
                    }
                }
            }
        }

        if ($this->request->getGet('order') && count($this->request->getGet('order'))) {
            $order = $this->request->getGet('order')[0];

            if (isset($this->column[$order['column']]) && $this->column[$order['column']]['orderable'])
                $params['order'] = array(
                    $this->column[$order['column']]['column'] =>  $order['dir'] === 'asc' ? "ASC" : "DESC"
                );
        }
        return $params;
    }

    public function populate()
    {
        try {

            // if($this->isEloquent){

            $params = $this->setParams();
            // return $this->getColumnsOnly();
            $result = $this->eloquent::findAll($params, $this->returnEntity, $this->getColumnsOnly());

            $this->output["draw"] = !empty($this->request->getGet('draw')) ? intval($this->request->getGet('draw')) : 0;
            $this->output["recordsTotal"] = intval(count($result));
            $this->output["recordsFiltered"] = intval($this->allData($params));
            $this->output["data"] = $this->output($result);

            // }

        } catch (Exception $e) {
            $this->output["error"] = $e->getMessage();
        }

        return $this->output;
    }

    private function allData($filter = array())
    {
        $params = array(
            'join' => isset($filter['join']) ? $filter['join'] : null,
            'where' => isset($filter['where']) ? $filter['where'] : null,
            'whereIn' => isset($filter['whereIn']) ? $filter['whereIn'] : null,
            'orWhere' => isset($filter['orWhere']) ? $filter['orWhere'] : null,
            'whereNotIn' => isset($filter['whereNotIn']) ? $filter['whereNotIn'] : null,
            'like' => isset($filter['like']) ? $filter['like'] : null,
            'orLike' => isset($filter['orLike']) ? $filter['orLike'] : null,
            'group' => isset($filter['group']) ? $filter['group'] : null,
        );
        // return null;
        return $this->eloquent::count($params, $this->returnEntity, $this->getColumnsOnly());
    }

    public function addColumn($column, $foreignKey = null, $callback = null, $searchable = true, $orderable = true, $isdefaultorder = false)
    {

        $columns = array(
            'column' => $column,
            'foreignKey' => $foreignKey,
            'callback' => $callback,
            'searchable' => $searchable,
            'orderable' => $orderable,
            'isdefaultorder' => $isdefaultorder
        );
        array_push($this->column, $columns);
        $this->columnCounter++;
        return $this;
    }

    private function output($datas)
    {
        $out = array();
        foreach ($datas as $data) {
            $row = array();
            $i = 0;
            foreach ($this->column as $column) {
                $rowdata = null;

                if (!is_null($column['callback'])) {
                    $rowdata = $column['callback']($data);
                } else {
                    $rowdata = $this->getColValue($column, $data);
                }

                if ($this->useIndex) {
                    $row[] = $rowdata;
                } else {
                    $selectedColumn = "";
                    $col = explode(".", $column['column']);
                    if (count($col) == 3) {
                        $selectedColumn = $col[2];
                    } else if (count($col) == 2) {
                        $selectedColumn = $col[1];
                    } else {
                        $selectedColumn = $col[0];
                    }
                    $row[$selectedColumn] = $rowdata;
                }

                if ($this->dtRowId && $this->dtRowId == $column['column']) {
                    $rowid = $this->dtRowId;
                    $row['DT_RowId'] = $data->$rowid;
                }

                $row['DT_RowClass'] = $this->dtRowClass;
                $i++;
            }
            $out[] = $row;
        }
        return $out;
    }

    public function addDtRowClass($className)
    {
        $this->dtRowClass = $className;
        return $this;
    }

    public function addDtRowId($columName)
    {
        $this->dtRowId = $columName;
        return $this;
    }

    private function getColValue($column, $data)
    {
        if ($this->returnEntity) {
            $nameSpace = explode("\\", $this->eloquent);

            if (!is_null($column['column'])) {
                $col = explode(".", $column['column']);
                if (count($col) == 3) {
                    $newobj = new $this->eloquent;
                    if ($newobj->getTableName() != $col[0]) {
                        $selectedColumn = $col[1];
                        return $data->hasOneOrNew($nameSpace[0] . "\\" . $nameSpace[1] . "\\" . $col[0], $column['foreignKey'])->$selectedColumn;
                    } else {
                        $selectedColumn = $col[1];
                        return $data->$selectedColumn;
                    }
                } else {
                    $selectedColumn = $col[0];
                    return $data->$selectedColumn;
                }
            }
        } else {
            $col = explode(".", $column['column']);
            $columnname = null;
            if (count($col) == 3) {
                $columnname = $col[2];
                return $data->$columnname;
            } else if (count($col) == 2) {
                $columnname = $col[1];
                return $data->$columnname;
            } else {
                $columnname = $column['column'];
                return $data->$columnname;
            }
        }
        return null;
    }

    public function getColumns()
    {
        return $this->column;
    }

    private function getColumnsOnly()
    {
        $columns = [];
        foreach ($this->column as $column) {
            $col = explode(".", $column['column']);
            if (count($col) == 3) {
                $columns[] =  $col[0] . "." . $col[1] . " " . $col[2];
            } else {
                $columns[] = $column['column'];
            }
        }
        return $columns;
    }
}
