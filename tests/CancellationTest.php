<?php

namespace {
    if (!defined('READ')) {
        define('READ', 1);
    }

    if (!class_exists('CommonDBTM')) {
        class CommonDBTM {
            public array $fields = [];
            public function getID(): int {
                return (int) ($this->fields['id'] ?? 0);
            }
        }
    }

    if (!class_exists('Session')) {
        class Session {
            public static function haveRight(string $right, int $value): bool {
                return !empty($_SESSION['glpiactiveprofile'][$right]);
            }
            public static function getLoginUserID(): int {
                return (int) ($_SESSION['glpiID'] ?? 0);
            }
        }
    }

    if (!class_exists('Toolbox')) {
        class Toolbox {
            public static function logInFile(string $file, string $msg): void {}
        }
    }

    if (!class_exists('Log')) {
        class Log {
            public const HISTORY_LOG_SIMPLE_MESSAGE = 1;
            public static function history(...$args): void {}
        }
    }

    if (!class_exists('User')) {
        class User {
            public function getFromDB(int $id): bool { return true; }
            public function getName(): string { return 'Test User'; }
        }
    }

    if (!function_exists('__')) {
        function __(string $text, string $domain = 'glpi'): string {
            return $text;
        }
    }

    if (!function_exists('_n')) {
        function _n(string $singular, string $plural, int $number, string $domain = 'glpi'): string {
            return $number <= 1 ? $singular : $plural;
        }
    }
}

namespace GlpiPlugin\Fleetbooking\Tests {

    require_once __DIR__ . '/FixesReviewTest.php';
    require_once __DIR__ . '/../src/Request.php';
    require_once __DIR__ . '/../src/Service/CancelService.php';
    require_once __DIR__ . '/../src/Service/ApprovalService.php';

    use GlpiPlugin\Fleetbooking\Request;
    use GlpiPlugin\Fleetbooking\Service\CancelService;
    use GlpiPlugin\Fleetbooking\Service\ApprovalService;

    class CancellationTest extends LocalTestCase
    {
        public function runAll(): void
        {
            $methods = get_class_methods($this);
            $tests = array_filter($methods, fn($m) => str_starts_with($m, 'test'));
            $passed = 0;

            echo "\n=== Running FleetBooking Cancellation Test Suite ===\n";

            foreach ($tests as $test) {
                try {
                    $this->$test();
                    echo "  ✓ $test\n";
                    $passed++;
                } catch (\Throwable $e) {
                    echo "  ✗ $test\n";
                    echo "    └─ " . $e->getMessage() . "\n";
                    throw $e;
                }
            }

            echo "\nCancellation Tests Result: $passed passed, 0 failed\n";
        }

        public function testStatusCancelledConstantExists(): void
        {
            $this->assertSame('cancelled', Request::STATUS_CANCELLED);
            $allStatuses = Request::getAllStatuses();
            $this->assertTrue(isset($allStatuses[Request::STATUS_CANCELLED]));
            $this->assertSame('Cancelled', $allStatuses[Request::STATUS_CANCELLED]);
        }

        public function testCanCancelRequesterSelf(): void
        {
            $service = new CancelService();

            $req = new Request();
            $req->fields = [
                'id' => 10,
                'status' => Request::STATUS_APPROVED,
                'requester_users_id' => 42,
                'manager_users_id' => 99,
                'end_datetime' => date('Y-m-d H:i:s', strtotime('+2 days')),
            ];

            // Requester can cancel
            $this->assertTrue($service->canCancel($req, 42));

            // Random user cannot cancel
            $_SESSION['glpiactiveprofile']['fleetbooking_cancel'] = 0;
            $_SESSION['glpiactiveprofile']['fleetbooking_admin'] = 0;
            $this->assertFalse($service->canCancel($req, 77));
        }

        public function testCanCancelManagerSubordinate(): void
        {
            $service = new CancelService();

            $req = new Request();
            $req->fields = [
                'id' => 11,
                'status' => Request::STATUS_APPROVED,
                'requester_users_id' => 42,
                'manager_users_id' => 99,
                'end_datetime' => date('Y-m-d H:i:s', strtotime('+2 days')),
            ];

            // Manager of this request can cancel
            $this->assertTrue($service->canCancel($req, 99));
        }

        public function testCanCancelTechnicianWithCancelRight(): void
        {
            $service = new CancelService();

            $req = new Request();
            $req->fields = [
                'id' => 12,
                'status' => Request::STATUS_APPROVED,
                'requester_users_id' => 42,
                'manager_users_id' => 99,
                'end_datetime' => date('Y-m-d H:i:s', strtotime('+2 days')),
            ];

            // User with fleetbooking_cancel can cancel even if not manager or requester
            $_SESSION['glpiactiveprofile']['fleetbooking_cancel'] = 1;
            $_SESSION['glpiactiveprofile']['fleetbooking_admin'] = 0;
            $this->assertTrue($service->canCancel($req, 105));

            // Reset
            $_SESSION['glpiactiveprofile']['fleetbooking_cancel'] = 0;
        }

        public function testPastReservationCannotBeCancelled(): void
        {
            $service = new CancelService();

            $req = new Request();
            $req->fields = [
                'id' => 13,
                'status' => Request::STATUS_APPROVED,
                'requester_users_id' => 42,
                'manager_users_id' => 99,
                'end_datetime' => date('Y-m-d H:i:s', strtotime('-1 hour')), // Ended in the past
            ];

            $_SESSION['glpiactiveprofile']['fleetbooking_admin'] = 1;
            // Even admin cannot cancel a reservation that has already finished
            $this->assertFalse($service->canCancel($req, 42));
            $_SESSION['glpiactiveprofile']['fleetbooking_admin'] = 0;
        }

        public function testActiveInProgressReservationCanBeCancelled(): void
        {
            $service = new CancelService();

            $req = new Request();
            $req->fields = [
                'id' => 14,
                'status' => Request::STATUS_APPROVED,
                'requester_users_id' => 42,
                'manager_users_id' => 99,
                'start_datetime' => date('Y-m-d H:i:s', strtotime('-1 hour')), // Started 1 hour ago
                'end_datetime' => date('Y-m-d H:i:s', strtotime('+2 hours')), // Ends in 2 hours
            ];

            $this->assertTrue($service->canCancel($req, 42));
        }

        public function testAlreadyRejectedOrCancelledCannotBeCancelled(): void
        {
            $service = new CancelService();

            $req = new Request();
            $req->fields = [
                'id' => 15,
                'status' => Request::STATUS_CANCELLED,
                'requester_users_id' => 42,
                'end_datetime' => date('Y-m-d H:i:s', strtotime('+1 day')),
            ];

            $this->assertFalse($service->canCancel($req, 42));

            $req->fields['status'] = Request::STATUS_REJECTED;
            $this->assertFalse($service->canCancel($req, 42));
        }

        public function testApprovalBypassGuardsAgainstTicketHookReentrancy(): void
        {
            $this->assertFalse(ApprovalService::isInApprovalProcess());
            $this->assertFalse(CancelService::isInCancellationProcess());
        }

        protected function assertTrue($val, string $msg = ''): void
        {
            if ($val !== true) {
                throw new \RuntimeException($msg ?: 'Expected true but got ' . var_export($val, true));
            }
        }

        protected function assertFalse($val, string $msg = ''): void
        {
            if ($val !== false) {
                throw new \RuntimeException($msg ?: 'Expected false but got ' . var_export($val, true));
            }
        }
    }

    $testSuite = new CancellationTest();
    $testSuite->runAll();
}
