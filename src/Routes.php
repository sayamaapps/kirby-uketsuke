<?php

namespace Sayamaapps\Uketsuke;

use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Http\Request;
use Kirby\Http\Response;
use Throwable;

/**
 * Defines the four routes of the module.
 *
 * Every route is registered, and each answers 404 (null) while the module is inactive.
 * The protocol and retry routes also answer 404 without a token option.
 *
 * The handlers are public because Kirby runs route actions through Closure::call(),
 * which rebinds the class scope: a private `self::` call inside the action would fail.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Routes
{
	/**
	 * Returns the route definitions for Kirby's `routes` option.
	 *
	 * @return list<array{pattern: string, method: string, action: callable}>
	 */
	public static function all(App $kirby): array
	{
		$path = Options::fromKirby($kirby)->path();

		return [
			['pattern' => $path, 'method' => 'POST', 'action' => fn () => Routes::receive($kirby)],
			['pattern' => $path, 'method' => 'GET', 'action' => fn () => Routes::ping($kirby)],
			['pattern' => $path . '/protokoll', 'method' => 'GET', 'action' => fn () => Routes::protocol($kirby)],
			['pattern' => $path . '/wiederholen', 'method' => 'POST', 'action' => fn () => Routes::retry($kirby)],
		];
	}

	/**
	 * Reads since and until (YYYY-MM-DD, Europe/Berlin). Since defaults to 30 days ago. Null when a date is malformed.
	 *
	 * @return array{since: DateTimeImmutable, until: DateTimeImmutable|null}|null
	 */
	private static function period(Request $request): array|null
	{
		$zone = new DateTimeZone('Europe/Berlin');
		$since = $request->get('since');
		$until = $request->get('until');
		$untilGiven = is_string($until) && $until !== '';

		$sinceDate = is_string($since) && $since !== ''
			? Protocol::date($since)
			: (new DateTimeImmutable('today', $zone))->modify('-30 days');
		$untilDate = $untilGiven ? Protocol::date($until) : null;

		if ($sinceDate === null || ($untilGiven && $untilDate === null)) {
			return null;
		}

		return ['since' => $sinceDate, 'until' => $untilDate];
	}

	/**
	 * GET {path}: reachability only.
	 */
	public static function ping(App $kirby): Response|null
	{
		if (Options::fromKirby($kirby)->active() === false) {
			return null;
		}

		return Reply::text(200, 'OK');
	}

	/**
	 * GET {path}/protokoll?since=YYYY-MM-DD&until=YYYY-MM-DD (bearer token).
	 */
	public static function protocol(App $kirby): Response|null
	{
		$options = Options::fromKirby($kirby);
		$token = $options->token();

		if ($options->active() === false || $token === null) {
			return null;
		}

		if (Auth::granted($kirby->request(), $token) === false) {
			return Reply::text(401, 'UNAUTHORIZED');
		}

		$period = self::period($kirby->request());

		if ($period === null) {
			return Reply::text(400, 'BAD_DATE');
		}

		try {
			$ledger = new Ledger($options->storageDirectory($kirby));

			return Reply::json(Protocol::build($ledger, $period['since'], $period['until']));
		} catch (Throwable $e) {
			error_log('Uketsuke: protocol failed (' . $e::class . ')');
			return Reply::text(503, 'STORAGE_ERROR');
		}
	}

	/**
	 * POST {path}: the IPN receiver.
	 */
	public static function receive(App $kirby): Response|null
	{
		if (Options::fromKirby($kirby)->active() === false) {
			return null;
		}

		return (new Receiver($kirby))->handle($kirby->request()->data());
	}

	/**
	 * Runs the retry for the order id, or for channel and status. Null when the request names neither.
	 *
	 * @return list<string>|null
	 */
	private static function retried(App $kirby, Options $options, Request $request): array|null
	{
		$orderId = $request->get('order_id');
		$channel = $request->get('channel');
		$status = $request->get('status');
		$ledger = new Ledger($options->storageDirectory($kirby));

		if (is_string($orderId) && $orderId !== '') {
			return Retry::order($kirby, $ledger, $orderId);
		}

		if (is_string($channel) && $channel !== '' && is_string($status) && $status !== '') {
			return Retry::channel($kirby, $ledger, $channel, $status);
		}

		return null;
	}

	/**
	 * POST {path}/wiederholen with order_id, or channel and status (bearer token).
	 */
	public static function retry(App $kirby): Response|null
	{
		$options = Options::fromKirby($kirby);
		$token = $options->token();

		if ($options->active() === false || $token === null) {
			return null;
		}

		if (Auth::granted($kirby->request(), $token) === false) {
			return Reply::text(401, 'UNAUTHORIZED');
		}

		try {
			$triggered = self::retried($kirby, $options, $kirby->request());
		} catch (Throwable $e) {
			error_log('Uketsuke: retry failed (' . $e::class . ')');
			return Reply::text(503, 'STORAGE_ERROR');
		}

		if ($triggered === null) {
			return Reply::text(400, 'BAD_REQUEST');
		}

		return Reply::json(['triggered' => $triggered]);
	}
}
