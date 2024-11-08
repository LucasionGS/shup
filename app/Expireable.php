<?php

namespace App;

/**
 * 
 * Interface for expireable models. Must have an expires column
 */
interface Expireable
{
    /**
     * Delete all expired items. Should will most likely call expire() on each item that has overpassed its expiration date.
     * @return int The number of items deleted
     */
    public static function deleteExpired(): int;

    /**
     * Expire the item. Will delete the item from the database and perform any other necessary actions.
     * @return void
     */
    public function expire(): void;
}
