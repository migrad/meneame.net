<?php

namespace search;

interface iSearch
{
    public function search($by_date = false, $start = 0, $count = 50);

}