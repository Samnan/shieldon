<?php
/*
 * This file is part of the Shieldon package.
 *
 * (c) Terry L. <contact@terryl.in>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Shieldon\Firewall\Kernel;

use Shieldon\Firewall\Kernel;
use function file_exists;
use function file_put_contents;
use function filter_var;
use function is_writable;
use function time;

/*
 * @since 1.0.0
 */
trait RuleTrait
{
    /**
     * Look up the rule table.
     *
     * If a specific IP address doesn't exist, return false. 
     * Otherwise, return true.
     *
     * @return bool
     */
    private function DoesRuleExist()
    {
        $ipRule = $this->driver->get($this->ip, 'rule');

        if (empty($ipRule)) {
            return false;
        }

        $ruleType = (int) $ipRule['type'];

        // Apply the status code.
        $this->result = $ruleType;

        if ($ruleType === kernel::ACTION_ALLOW) {
            return true;
        }

        // Current visitor has been blocked. If he still attempts accessing the site, 
        // then we can drop him into the permanent block list.
        $attempts = $ipRule['attempts'] ?? 0;
        $attempts = (int) $attempts;
        $now = time();
        $logData = [];
        $handleType = 0;

        $logData['log_ip']     = $ipRule['log_ip'];
        $logData['ip_resolve'] = $ipRule['ip_resolve'];
        $logData['time']       = $now;
        $logData['type']       = $ipRule['type'];
        $logData['reason']     = $ipRule['reason'];
        $logData['attempts']   = $attempts;

        // @since 0.2.0
        $attemptPeriod = $this->properties['record_attempt_detection_period'];
        $attemptReset  = $this->properties['reset_attempt_counter'];

        $lastTimeDiff = $now - $ipRule['time'];

        if ($lastTimeDiff <= $attemptPeriod) {
            $logData['attempts'] = ++$attempts;
        }

        if ($lastTimeDiff > $attemptReset) {
            $logData['attempts'] = 0;
        }

        if ($ruleType === kernel::ACTION_TEMPORARILY_DENY) {
            $ratd = $this->determineAttemptsTemporaryDeny($logData, $handleType, $attempts);
            $logData = $ratd['log_data'];
            $handleType = $ratd['handle_type'];
        }

        if ($ruleType === kernel::ACTION_DENY) {
            $rapd = $this->determineAttemptsPermanentDeny($logData, $handleType, $attempts);
            $logData = $rapd['log_data'];
            $handleType = $rapd['handle_type'];
        }

        // We only update data when `deny_attempt_enable` is enable.
        // Because we want to get the last visited time and attempt counter.
        // Otherwise, we don't update it everytime to avoid wasting CPU resource.
        if ($this->event['update_rule_table']) {
            $this->driver->save($this->ip, $logData, 'rule');
        }

        // Notify this event to messenger.
        if ($this->event['trigger_messengers']) {
            $this->prepareMessengerBody($logData, $handleType);
        }

        return true;
    }

    /**
     * Record the attempts when the user is temporarily denied by rule table.
     *
     * @param array $logData
     * @param int   $handleType
     * @param int   $attempts
     * 
     * @return array
     */
    private function determineAttemptsTemporaryDeny(array $logData, int $handleType, int $attempts): array
    {
        if ($this->properties['deny_attempt_enable']['data_circle']) {
            $this->event['update_rule_table'] = true;

            $buffer = $this->properties['deny_attempt_buffer']['data_circle'];

            if ($attempts >= $buffer) {

                if ($this->properties['deny_attempt_notify']['data_circle']) {
                    $this->event['trigger_messengers'] = true;
                }

                $logData['type'] = kernel::ACTION_DENY;

                // Reset this value for next checking process - iptables.
                $logData['attempts'] = 0;
                $handleType = 1;
            }
        }

        return [
            'log_data' => $logData,
            'handle_type' => $handleType,
        ];
    }

    /**
     * Record the attempts when the user is permanently denied by rule table.
     *
     * @param array $logData
     * @param int   $handleType
     * @param int   $attempts
     * 
     * @return array
     */
    private function determineAttemptsPermanentDeny(array $logData, int $handleType, int $attempts): array
    {
        if ($this->properties['deny_attempt_enable']['system_firewall']) {
            $this->event['update_rule_table'] = true;

            // For the requests that are already banned, but they are still attempting access, that means 
            // that they are programmably accessing your website. Consider put them in the system-layer fireall
            // such as IPTABLE.
            $bufferIptable = $this->properties['deny_attempt_buffer']['system_firewall'];

            if ($attempts >= $bufferIptable) {

                if ($this->properties['deny_attempt_notify']['system_firewall']) {
                    $this->event['trigger_messengers'] = true;
                }

                $folder = rtrim($this->properties['iptables_watching_folder'], '/');

                if (file_exists($folder) && is_writable($folder)) {
                    $filePath = $folder . '/iptables_queue.log';

                    // command, ipv4/6, ip, subnet, port, protocol, action
                    // add,4,127.0.0.1,null,all,all,drop  (example)
                    // add,4,127.0.0.1,null,80,tcp,drop   (example)
                    $command = 'add,4,' . $this->ip . ',null,all,all,deny';

                    if (filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $command = 'add,6,' . $this->ip . ',null,all,allow';
                    }

                    // Add this IP address to itables_queue.log
                    // Use `bin/iptables.sh` for adding it into IPTABLES. See document for more information. 
                    file_put_contents($filePath, $command . "\n", FILE_APPEND | LOCK_EX);

                    $logData['attempts'] = 0;
                    $handleType = 2;
                }
            }
        }

        return [
            'log_data' => $logData,
            'handle_type' => $handleType,
        ];
    }
}