<?php

class StatsBestProductsFixture extends statsbestproducts
{
  public function configureGrid(Employee $employee, $lang, $sort, $direction, $start, $limit)
  {
    $this->_employee = $employee;
    $this->setLang($lang);
    $this->_sort = $sort;
    $this->_direction = $direction;
    $this->_start = $start;
    $this->_limit = $limit;
  }

  public function getGridValues()
  {
    return $this->_values;
  }

  public function getTotalCount()
  {
    return $this->_totalCount;
  }
}
