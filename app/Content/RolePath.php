<?php

namespace App\Content;

/**
 * The Role Paths (GLOSSARY.md; plan, "Listings"): one per Staff Role in First Mile Manager, each
 * listing every staff Article tagged with that role's Team Roles. A Warehouse Manager does all a
 * Warehouse Operative does, so their path lists the Warehouse Operative's Articles too.
 */
enum RolePath: string
{
    case Admin = 'admin';
    case FinanceManager = 'finance-manager';
    case AccountManager = 'account-manager';
    case TransportManager = 'transport-manager';
    case Driver = 'driver';
    case WarehouseManager = 'warehouse-manager';
    case WarehouseOperative = 'warehouse-operative';

    /** The Role Path's name, as its tile says it after "I'm a…". */
    public function title(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::FinanceManager => 'Finance Manager',
            self::AccountManager => 'Account Manager',
            self::TransportManager => 'Transport Manager',
            self::Driver => 'Driver',
            self::WarehouseManager => 'Warehouse Manager',
            self::WarehouseOperative => 'Warehouse Operative',
        };
    }

    /** "I’m an Admin". */
    public function tile(): string
    {
        return 'I’m '.(preg_match('/\A[AEIOU]/', $this->title()) === 1 ? 'an' : 'a').' '.$this->title();
    }

    /** What a reader in this role does, under the tile, as First Mile Manager's roles form says it. */
    public function blurb(): string
    {
        return match ($this) {
            self::Admin => 'Set up your business and your team, and give people their roles.',
            self::FinanceManager => 'Look after your business’s money: collection charges and, later, invoices.',
            self::AccountManager => 'Look after clients: their details, contacts, schedules, queries and failures.',
            self::TransportManager => 'Keep vehicles, organise routes, watch progress and invite Drivers.',
            self::Driver => 'Drive collection rounds and record proof of collection and failures.',
            self::WarehouseManager => 'Check in collected goods, settle check-in exceptions and invite Warehouse Operatives.',
            self::WarehouseOperative => 'Check in collected goods.',
        };
    }

    /**
     * The Team Roles (exactly as an Article's team_roles names them) whose Articles this path
     * lists.
     *
     * @return list<string>
     */
    public function teamRoles(): array
    {
        return match ($this) {
            self::WarehouseManager => ['Warehouse Manager', 'Warehouse Operative'],
            default => [$this->title()],
        };
    }
}
