<?php

namespace Tests\Support;

use App\Core\BaseRepository;

/**
 * @extends BaseRepository<Item>
 */
class ItemRepository extends BaseRepository
{
    public function __construct(Item $entity = new Item)
    {
        parent::__construct($entity);
    }
}
