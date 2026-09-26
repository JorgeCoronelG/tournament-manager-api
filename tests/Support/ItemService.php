<?php

namespace Tests\Support;

use App\Core\BaseService;
use App\Core\Contracts\BaseRepositoryInterface;

/**
 * @extends BaseService<Item>
 */
class ItemService extends BaseService
{
    /**
     * @param  BaseRepositoryInterface<Item>  $entityRepository
     */
    public function __construct(BaseRepositoryInterface $entityRepository)
    {
        parent::__construct($entityRepository);
    }
}
