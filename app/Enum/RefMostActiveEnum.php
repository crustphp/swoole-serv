<?php

namespace App\Enum;

enum RefMostActiveEnum:string
{
    case TOPGAINER = 'ref_top_gainers';
    case FREQUENTLYTRADEDSTOCK = 'ref_frequently_traded_stocks';
    case DONEDEALCOUNT = 'ref_done_deal_counts';
    case MOSTACTIVEVALUE = 'ref_most_active_values';

}
