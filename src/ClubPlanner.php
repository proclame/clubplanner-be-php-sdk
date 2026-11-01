<?php

namespace Proclame\ClubPlanner;

use GuzzleHttp\ClientInterface;
use Proclame\ClubPlanner\Resources\Actions;
use Proclame\ClubPlanner\Resources\Balances;
use Proclame\ClubPlanner\Resources\Bills;
use Proclame\ClubPlanner\Resources\CalendarItems;
use Proclame\ClubPlanner\Resources\Calendars;
use Proclame\ClubPlanner\Resources\Chats;
use Proclame\ClubPlanner\Resources\CheckIn;
use Proclame\ClubPlanner\Resources\ClientDisplays;
use Proclame\ClubPlanner\Resources\Clubs;
use Proclame\ClubPlanner\Resources\Coach;
use Proclame\ClubPlanner\Resources\Coupons;
use Proclame\ClubPlanner\Resources\Credits;
use Proclame\ClubPlanner\Resources\Employees;
use Proclame\ClubPlanner\Resources\General;
use Proclame\ClubPlanner\Resources\Invoices;
use Proclame\ClubPlanner\Resources\Io;
use Proclame\ClubPlanner\Resources\Leads;
use Proclame\ClubPlanner\Resources\Members;
use Proclame\ClubPlanner\Resources\MoneyInOut;
use Proclame\ClubPlanner\Resources\OpenItems;
use Proclame\ClubPlanner\Resources\Orders;
use Proclame\ClubPlanner\Resources\PayMethods;
use Proclame\ClubPlanner\Resources\Pos;
use Proclame\ClubPlanner\Resources\Reservations;
use Proclame\ClubPlanner\Resources\Retention;
use Proclame\ClubPlanner\Resources\Sales;
use Proclame\ClubPlanner\Resources\Subscriptions;
use Proclame\ClubPlanner\Resources\Visits;
use Proclame\ClubPlanner\Resources\Vouchers;
use Proclame\ClubPlanner\Resources\WaitingList;

/**
 * Entry point of the SDK: one instance talks to one Club Planner platform.
 *
 *     $clubPlanner = new ClubPlanner('https://your-club.clubplanner.app', $apiKey);
 *     $member = $clubPlanner->members()->get(123);
 */
class ClubPlanner
{
    protected Transport $transport;

    protected ApiDate $dates;

    /**
     * @var array<class-string<ApiResource>, ApiResource>
     */
    protected array $resources = [];

    /**
     * @param  string  $baseUrl  The root of the platform, without a path.
     * @param  string  $timezone  The club's timezone: the API's dates carry no offset.
     * @param  array<int, string>  $redact  Response keys to strip at any depth, such as Redactor::SENSITIVE_FIELDS.
     * @param  ClientInterface|null  $http  A Guzzle client of your own, for proxies, middleware or tests.
     *
     * @throws ClubPlannerException
     */
    public function __construct(
        string $baseUrl,
        ?string $apiKey,
        string $version = 'v1',
        int|float $timeout = 15,
        string $timezone = 'Europe/Brussels',
        array $redact = [],
        ?ClientInterface $http = null,
    ) {
        $this->dates = new ApiDate($timezone);
        $this->transport = new Transport(
            $baseUrl,
            $apiKey,
            $this->dates,
            $version,
            $timeout,
            $redact === [] ? null : new Redactor($redact),
            $http,
        );
    }

    /**
     * Check connectivity. The one endpoint that answers without a key, so this
     * says nothing about whether the key is valid.
     *
     * @return array<mixed>
     *
     * @throws ClubPlannerException
     */
    public function ping(): array
    {
        return $this->transport->get('ping');
    }

    /**
     * A filter builder for members()->list() and members()->count(), carrying
     * this platform's timezone.
     */
    public function memberFilter(): MemberFilter
    {
        return new MemberFilter($this->dates);
    }

    /**
     * Raw access, for an endpoint this SDK does not cover yet.
     */
    public function transport(): Transport
    {
        return $this->transport;
    }

    public function actions(): Actions
    {
        return $this->resource(Actions::class);
    }

    public function balances(): Balances
    {
        return $this->resource(Balances::class);
    }

    public function bills(): Bills
    {
        return $this->resource(Bills::class);
    }

    public function calendarItems(): CalendarItems
    {
        return $this->resource(CalendarItems::class);
    }

    public function calendars(): Calendars
    {
        return $this->resource(Calendars::class);
    }

    public function chats(): Chats
    {
        return $this->resource(Chats::class);
    }

    public function checkIn(): CheckIn
    {
        return $this->resource(CheckIn::class);
    }

    public function clientDisplays(): ClientDisplays
    {
        return $this->resource(ClientDisplays::class);
    }

    public function clubs(): Clubs
    {
        return $this->resource(Clubs::class);
    }

    public function coach(): Coach
    {
        return $this->resource(Coach::class);
    }

    public function coupons(): Coupons
    {
        return $this->resource(Coupons::class);
    }

    public function credits(): Credits
    {
        return $this->resource(Credits::class);
    }

    public function employees(): Employees
    {
        return $this->resource(Employees::class);
    }

    public function general(): General
    {
        return $this->resource(General::class);
    }

    public function invoices(): Invoices
    {
        return $this->resource(Invoices::class);
    }

    public function io(): Io
    {
        return $this->resource(Io::class);
    }

    public function leads(): Leads
    {
        return $this->resource(Leads::class);
    }

    public function members(): Members
    {
        return $this->resource(Members::class);
    }

    public function moneyInOut(): MoneyInOut
    {
        return $this->resource(MoneyInOut::class);
    }

    public function openItems(): OpenItems
    {
        return $this->resource(OpenItems::class);
    }

    public function orders(): Orders
    {
        return $this->resource(Orders::class);
    }

    public function payMethods(): PayMethods
    {
        return $this->resource(PayMethods::class);
    }

    public function pos(): Pos
    {
        return $this->resource(Pos::class);
    }

    public function reservations(): Reservations
    {
        return $this->resource(Reservations::class);
    }

    public function retention(): Retention
    {
        return $this->resource(Retention::class);
    }

    public function sales(): Sales
    {
        return $this->resource(Sales::class);
    }

    public function subscriptions(): Subscriptions
    {
        return $this->resource(Subscriptions::class);
    }

    public function visits(): Visits
    {
        return $this->resource(Visits::class);
    }

    public function vouchers(): Vouchers
    {
        return $this->resource(Vouchers::class);
    }

    public function waitingList(): WaitingList
    {
        return $this->resource(WaitingList::class);
    }

    /**
     * @template T of ApiResource
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function resource(string $class): ApiResource
    {
        /** @var T */
        return $this->resources[$class] ??= new $class($this->transport, $this->dates);
    }
}
