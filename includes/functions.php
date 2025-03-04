<?php

function getStats() {
    $rJSON = array();
    $rJSON['cpu'] = round(getTotalCPU(), 2);
    $rJSON['cpu_cores'] = intval(shell_exec('cat /proc/cpuinfo | grep "^processor" | wc -l'));
    $rJSON['cpu_avg'] = round((sys_getloadavg()[0] * 100) / (($rJSON['cpu_cores'] ?: 1)), 2);
    $rJSON['cpu_name'] = trim(shell_exec("cat /proc/cpuinfo | grep 'model name' | uniq | awk -F: '{print \$2}'"));
    if ($rJSON['cpu_avg'] > 100) {
        $rJSON['cpu_avg'] = 100;
    }
    $rFree = explode("\n", trim(shell_exec('free')));
    $rMemory = preg_split('/[\\s]+/', $rFree[1]);
    $rTotalUsed = intval($rMemory[2]);
    $rTotalRAM = intval($rMemory[1]);
    $rJSON['total_mem'] = $rTotalRAM;
    $rJSON['total_mem_free'] = $rTotalRAM - $rTotalUsed;
    $rJSON['total_mem_used'] = $rTotalUsed + getTotalTmpfs();
    $rJSON['total_mem_used_percent'] = round($rJSON['total_mem_used'] / $rJSON['total_mem'] * 100, 2);
    $rJSON['total_disk_space'] = disk_total_space(IPTV_ROOT_PATH);
    $rJSON['free_disk_space'] = disk_free_space(IPTV_ROOT_PATH);
    $rJSON['kernel'] = trim(shell_exec('uname -r'));
    $rJSON['uptime'] = getUptime();
    $rJSON['total_running_streams'] = (int) trim(shell_exec('ps ax | grep -v grep | grep -c ffmpeg'));
    $rJSON['bytes_sent'] = 0;
    $rJSON['bytes_sent_total'] = 0;
    $rJSON['bytes_received'] = 0;
    $rJSON['bytes_received_total'] = 0;
    $rJSON['network_speed'] = 0;
    $rJSON['interfaces'] = getNetworkInterfaces();
    $rJSON['network_speed'] = 0;
    if ($rJSON['cpu'] > 100) {
        $rJSON['cpu'] = 100;
    }
    if ($rJSON['total_mem'] < $rJSON['total_mem_used']) {
        $rJSON['total_mem_used'] = $rJSON['total_mem'];
    }
    if ($rJSON['total_mem_used_percent'] > 100) {
        $rJSON['total_mem_used_percent'] = 100;
    }

    $rJSON['network_info'] = getNetwork((ipTV_lib::$Servers[SERVER_ID]['network_interface'] == 'auto' ? null : ipTV_lib::$Servers[SERVER_ID]['network_interface']));
    foreach ($rJSON['network_info'] as $rInterface => $rData) {
        if (file_exists('/sys/class/net/' . $rInterface . '/speed')) {
            $NetSpeed = intval(file_get_contents('/sys/class/net/' . $rInterface . '/speed'));
            if (0 < $NetSpeed && $rJSON['network_speed'] == 0) {
                $rJSON['network_speed'] = $NetSpeed;
            }
        }
        $rJSON['bytes_sent_total'] = (intval(trim(file_get_contents('/sys/class/net/' . $rInterface . '/statistics/tx_bytes'))) ?: 0);
        $rJSON['bytes_received_total'] = (intval(trim(file_get_contents('/sys/class/net/' . $rInterface . '/statistics/tx_bytes'))) ?: 0);
        $rJSON['bytes_sent'] += $rData['bytes_sent'];
        $rJSON['bytes_received'] += $rData['bytes_received'];
    }
    $rJSON['audio_devices'] = array();
    $rJSON['video_devices'] = $rJSON['audio_devices'];
    $rJSON['gpu_info'] = $rJSON['video_devices'];
    $rJSON['iostat_info'] = $rJSON['gpu_info'];
    if (shell_exec('which iostat')) {
        $rJSON['iostat_info'] = getIO();
    }
    if (shell_exec('which nvidia-smi')) {
        $rJSON['gpu_info'] = getGPUInfo();
    }
    if (shell_exec('which v4l2-ctl')) {
        $rJSON['video_devices'] = getVideoDevices();
    }
    if (shell_exec('which arecord')) {
        $rJSON['audio_devices'] = getAudioDevices();
    }
    list($rJSON['cpu_load_average']) = sys_getloadavg();
    return $rJSON;
}
/**
 * Calculates the total CPU usage across all processes.
 *
 * This function retrieves the CPU usage of all running processes and sums them up.
 * It then normalizes the total CPU usage based on the number of CPU cores.
 *
 * @return float The total CPU usage percentage across all CPU cores.
 */
function getTotalCPU() {
    $rTotalLoad = 0; // Initialize variable to store the total CPU load.

    // Execute `ps -Ao pid,pcpu` to retrieve the list of all processes and their CPU usage.
    exec('ps -Ao pid,pcpu', $processes);

    // Iterate through each process.
    foreach ($processes as $process) {
        // Remove extra spaces and split the string into columns.
        $cols = explode(' ', preg_replace('!\\s+!', ' ', trim($process)));

        // Sum the CPU usage, converting the value to a float.
        $rTotalLoad += floatval($cols[1]);
    }

    // Get the number of CPU cores using `grep -P '^processor' /proc/cpuinfo | wc -l`.
    $cpuCores = intval(shell_exec("grep -P '^processor' /proc/cpuinfo | wc -l"));

    // Normalize the CPU load by dividing it by the number of CPU cores.
    return ($cpuCores > 0) ? $rTotalLoad / $cpuCores : 0;
}

/**
 * Calculates the total size of tmpfs (temporary filesystem) in use.
 *
 * This function retrieves the disk usage statistics for tmpfs filesystems
 * and sums up the used space in kilobytes.
 *
 * @return int Total tmpfs usage in kilobytes.
 */
function getTotalTmpfs() {
    $rTotal = 0; // Initialize variable to store the total tmpfs usage.

    // Execute `df | grep tmpfs` to list tmpfs filesystems and their usage.
    exec('df | grep tmpfs', $rOutput);

    foreach ($rOutput as $rLine) {
        // Normalize spacing and split the output into an array.
        $rSplit = explode(' ', preg_replace('!\s+!', ' ', trim($rLine)));

        // Ensure we are processing a valid tmpfs entry.
        if ($rSplit[0] === 'tmpfs') {
            $rTotal += intval($rSplit[2]); // Add used space (in KB).
        }
    }

    return $rTotal; // Return total tmpfs usage.
}

/**
 * Retrieves a list of active network interfaces.
 *
 * This function lists all available network interfaces except the loopback (`lo`)
 * and bonded interfaces (`bond*`).
 *
 * @return array List of network interface names.
 */
function getNetworkInterfaces() {
    $rReturn = array(); // Initialize an array to store network interfaces.

    // Execute `ls /sys/class/net/` to list network interfaces.
    exec('ls /sys/class/net/', $rOutput, $rReturnVar);

    foreach ($rOutput as $rInterface) {
        // Trim unnecessary characters from the interface name.
        $rInterface = trim(rtrim($rInterface, ':'));

        // Exclude the loopback interface (`lo`) and bonded interfaces (`bond*`).
        if ($rInterface !== 'lo' && substr($rInterface, 0, 4) != 'bond') {
            $rReturn[] = $rInterface;
        }
    }

    return $rReturn; // Return the list of network interfaces.
}

/**
 * Retrieves network statistics from a cached log file.
 *
 * This function reads the network statistics from a cached JSON file and
 * returns relevant data for all network interfaces or a specific interface.
 *
 * @param string|null $Interface The name of the network interface to filter (optional).
 * @return array Network statistics for the requested interface(s).
 */
function getNetwork($Interface = null) {
    $Return = array(); // Initialize an array to store network data.

    // Check if the network log file exists.
    if (file_exists(LOGS_TMP_PATH . 'network')) {
        // Read and decode network statistics from the JSON file.
        $Network = json_decode(file_get_contents(LOGS_TMP_PATH . 'network'), true);

        foreach ($Network as $Key => $Line) {
            // Filter results based on the provided interface name.
            // Exclude loopback (lo) and bonded (bond*) interfaces unless explicitly requested.
            if (!($Interface && $Key != $Interface) && !($Key == 'lo' || !$Interface && substr($Key, 0, 4) == 'bond')) {
                $Return[$Key] = $Line;
            }
        }
    }

    return $Return; // Return network statistics.
}

/**
 * Retrieves system I/O statistics using the `iostat` command.
 *
 * This function executes `iostat` in JSON format to obtain disk and CPU I/O usage
 * statistics and extracts relevant information.
 *
 * @return array Parsed I/O statistics, or an empty array if retrieval fails.
 */
function getIO() {
    // Execute `iostat -o JSON -m` to get I/O statistics in JSON format.
    exec('iostat -o JSON -m', $rOutput, $rReturnVar);

    // Combine output lines into a single JSON string.
    $rOutput = implode('', $rOutput);
    $rJSON = json_decode($rOutput, true);

    // Validate and return extracted statistics, or an empty array if unavailable.
    if (isset($rJSON['sysstat'])) {
        return $rJSON['sysstat']['hosts'][0]['statistics'][0];
    }

    return array();
}

/**
 * Retrieves information about the system's NVIDIA GPUs using `nvidia-smi`.
 *
 * @return array An array containing GPU details such as driver version, CUDA version, and utilization.
 */
function getGPUInfo() {
    exec('nvidia-smi -x -q', $rOutput, $rReturnVar);
    $rOutput = implode('', $rOutput);

    // Check if the output contains valid XML data
    if (stripos($rOutput, '<?xml') === false) {
        return array();
    }

    // Convert XML output to JSON and then decode it into an associative array
    $rJSON = json_decode(json_encode(simplexml_load_string($rOutput)), true);

    if (!isset($rJSON['driver_version'])) {
        return array();
    }

    // Initialize the result array with general GPU information
    $rGPU = array(
        'attached_gpus'  => $rJSON['attached_gpus'],
        'driver_version' => $rJSON['driver_version'],
        'cuda_version'   => $rJSON['cuda_version'],
        'gpus'           => array(),
    );

    // If there's only one GPU, convert it into an array
    if (isset($rJSON['gpu']['board_id'])) {
        $rJSON['gpu'] = array($rJSON['gpu']);
    }

    // Iterate through each GPU and extract relevant information
    foreach ($rJSON['gpu'] as $rInstance) {
        $rArray = array(
            'name'           => $rInstance['product_name'] ?? 'Unknown',
            'power_readings' => $rInstance['power_readings'] ?? array(),
            'utilisation'    => $rInstance['utilization'] ?? array(),
            'memory_usage'   => $rInstance['fb_memory_usage'] ?? array(),
            'fan_speed'      => $rInstance['fan_speed'] ?? 'N/A',
            'temperature'    => $rInstance['temperature'] ?? array(),
            'clocks'         => $rInstance['clocks'] ?? array(),
            'uuid'           => $rInstance['uuid'] ?? '',
            'id'             => isset($rInstance['pci']['pci_device']) ? intval($rInstance['pci']['pci_device']) : 0,
            'processes'      => array(),
        );

        // Extract running processes on the GPU
        if (!empty($rInstance['processes']['process_info'])) {
            $processes = is_array($rInstance['processes']['process_info']) ? $rInstance['processes']['process_info'] : array($rInstance['processes']['process_info']);
            foreach ($processes as $rProcess) {
                $rArray['processes'][] = array(
                    'pid'    => isset($rProcess['pid']) ? intval($rProcess['pid']) : 0,
                    'memory' => $rProcess['used_memory'] ?? 'N/A',
                );
            }
        }

        $rGPU['gpus'][] = $rArray;
    }

    return $rGPU;
}

/**
 * Retrieves a list of available video devices using `v4l2-ctl`.
 *
 * @return array An array containing video device names and corresponding device paths.
 */
function getVideoDevices() {
    $rReturn = array();
    $rID = 0;

    try {
        // Get the list of video devices
        $rDevices = array_values(array_filter(explode("\n", shell_exec('v4l2-ctl --list-devices'))));

        if (!is_array($rDevices)) {
            return $rReturn;
        }

        // Process the device list
        foreach ($rDevices as $rKey => $rValue) {
            if ($rKey % 2 == 0 && isset($rDevices[$rKey + 1])) {
                $rReturn[$rID]['name'] = trim($rValue);
                list(, $rReturn[$rID]['video_device']) = explode('/dev/', trim($rDevices[$rKey + 1]));
                $rID++;
            }
        }
    } catch (Exception $e) {
        return array();
    }

    return $rReturn;
}

/**
 * Retrieves a list of available audio devices using `arecord`.
 *
 * @return array An array of detected audio devices.
 */
function getAudioDevices() {
    try {
        // Use `arecord -L` to list all available audio devices
        return array_filter(explode("\n", shell_exec('arecord -L | grep "hw:CARD="')));
    } catch (Exception $e) {
        return array();
    }
}

function formatArrayResults($array, $key, $value, &$results) {
    if (!is_array($array)) {
        return;
    }
    if (isset($array[$key]) && $array[$key] == $value) {
        $results[] = $array;
    }
    foreach ($array as $item_value) {
        formatArrayResults($item_value, $key, $value, $results);
    }
}

/**
 * Checks for flood attempts based on IP address.
 *
 * This function checks for flood attempts based on the provided IP address.
 * It handles the restriction of flood attempts based on settings and time intervals.
 * If the IP is not provided, it retrieves the user's IP address.
 * It excludes certain IPs from flood checking based on settings.
 * It tracks and limits flood attempts within a specified time interval.
 * If the number of requests exceeds the limit, it blocks the IP and logs the attack.
 *
 * @param string|null $rIP (Optional) The IP address to check for flood attempts.
 * @return null|null Returns null if no flood attempt is detected, or a string indicating the block status if the IP is blocked.
 */
function checkFlood($rIP = null) {
    global $ipTV_db;
    if (ipTV_lib::$settings['flood_limit'] != 0) {
        if (!$rIP) {
            $rIP = ipTV_streaming::getUserIP();
        }
        if (!(empty($rIP) || in_array($rIP, ipTV_lib::$allowedIPs))) {
            $rFloodExclude = array_filter(array_unique(explode(',', ipTV_lib::$settings['flood_ips_exclude'])));
            if (!in_array($rIP, $rFloodExclude)) {
                $rIPFile = FLOOD_TMP_PATH . $rIP;
                if (file_exists($rIPFile)) {
                    $rFloodRow = json_decode(file_get_contents($rIPFile), true);
                    $rFloodSeconds = ipTV_lib::$settings['flood_seconds'];
                    $rFloodLimit = ipTV_lib::$settings['flood_limit'];
                    if (time() - $rFloodRow['last_request'] <= $rFloodSeconds) {
                        $rFloodRow['requests']++;
                        if ($rFloodLimit > $rFloodRow['requests']) {
                            $rFloodRow['last_request'] = time();
                            file_put_contents($rIPFile, json_encode($rFloodRow), LOCK_EX);
                        } else {
                            if (!in_array($rIP, ipTV_lib::$blockedISP)) {
                                if (ipTV_lib::$cached) {
                                    ipTV_lib::setSignal('flood_attack/' . $rIP, 1);
                                } else {
                                    $ipTV_db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $rIP, 'FLOOD ATTACK', time());
                                }
                                touch(FLOOD_TMP_PATH . 'block_' . $rIP);
                            }
                            ipTV_lib::unlinkFile($rIPFile);
                            return null;
                        }
                    } else {
                        $rFloodRow['requests'] = 0;
                        $rFloodRow['last_request'] = time();
                        file_put_contents($rIPFile, json_encode($rFloodRow), LOCK_EX);
                    }
                } else {
                    file_put_contents($rIPFile, json_encode(array('requests' => 0, 'last_request' => time())), LOCK_EX);
                }
            } else {
                return null;
            }
        } else {
            return null;
        }
    } else {
        return null;
    }
}
/**
 * Checks for authentication flood attempts for a user and IP address.
 *
 * This function checks for authentication flood attempts for a user and optional IP address.
 * It verifies if the user is not a restreamer and checks the IP address against allowed IPs and exclusions.
 * It tracks and limits authentication flood attempts based on settings and time intervals.
 * If the number of attempts exceeds the limit, it blocks further attempts until a specified time.
 *
 * @param array $rUser The user information containing the ID and restreamer status.
 * @param string|null $rIP (Optional) The IP address of the user.
 * @return null|null Returns null if no authentication flood attempt is detected, or a string indicating the block status if the user is blocked.
 */
function checkAuthFlood($rUser, $rIP = null) {
    if (ipTV_lib::$settings['auth_flood_limit'] != 0) {
        if (!$rUser['is_restreamer']) {
            if (!$rIP) {
                $rIP = ipTV_streaming::getUserIP();
            }
            if (!(empty($rIP) || in_array($rIP, ipTV_lib::$allowedIPs))) {
                $rFloodExclude = array_filter(array_unique(explode(',', ipTV_lib::$settings['flood_ips_exclude'])));
                if (!in_array($rIP, $rFloodExclude)) {
                    $rUserFile = FLOOD_TMP_PATH . intval($rUser['id']) . '_' . $rIP;
                    if (file_exists($rUserFile)) {
                        $rFloodRow = json_decode(file_get_contents($rUserFile), true);
                        $rFloodSeconds = ipTV_lib::$settings['auth_flood_seconds'];
                        $rFloodLimit = ipTV_lib::$settings['auth_flood_limit'];
                        $rFloodRow['attempts'] = truncateAttempts($rFloodRow['attempts'], $rFloodSeconds, true);
                        if ($rFloodLimit < count($rFloodRow['attempts'])) {
                            $rFloodRow['block_until'] = time() + intval(ipTV_lib::$settings['auth_flood_seconds']);
                        }
                        $rFloodRow['attempts'][] = time();
                        file_put_contents($rUserFile, json_encode($rFloodRow), LOCK_EX);
                    } else {
                        file_put_contents($rUserFile, json_encode(array('attempts' => array(time()))), LOCK_EX);
                    }
                } else {
                    return null;
                }
            } else {
                return null;
            }
        } else {
            return null;
        }
    } else {
        return null;
    }
}
/**
 * Checks for brute force attempts based on IP, MAC address, and username.
 *
 * This function checks for brute force attempts based on the provided IP, MAC address, and username.
 * It handles the restriction of brute force attempts based on settings and frequency.
 * If the IP is not provided, it retrieves the user's IP address.
 * It excludes certain IPs from flood checking based on settings.
 * It tracks and limits brute force attempts for MAC and username separately.
 * If the number of attempts exceeds the limit, it blocks the IP and logs the attack.
 *
 * @param string|null $rIP (Optional) The IP address of the user.
 * @param string|null $rMAC (Optional) The MAC address of the device.
 * @param string|null $rUsername (Optional) The username of the user.
 * @return null|null|string Returns null if no brute force attempt is detected, or a string indicating the type of attack if the IP is blocked.
 */
function checkBruteforce($rIP = null, $rMAC = null, $rUsername = null) {
    global $ipTV_db;
    if ($rMAC || $rUsername) {
        if (!($rMAC && ipTV_lib::$settings['bruteforce_mac_attempts'] == 0)) {
            if (!($rUsername && ipTV_lib::$settings['bruteforce_username_attempts'] == 0)) {
                if (!$rIP) {
                    $rIP = ipTV_streaming::getUserIP();
                }
                if (!(empty($rIP) || in_array($rIP, ipTV_lib::$allowedIPs))) {
                    $rFloodExclude = array_filter(array_unique(explode(',', ipTV_lib::$settings['flood_ips_exclude'])));
                    if (!in_array($rIP, $rFloodExclude)) {
                        $rFloodType = (!is_null($rMAC) ? 'mac' : 'user');
                        $rTerm = (!is_null($rMAC) ? $rMAC : $rUsername);
                        $rIPFile = FLOOD_TMP_PATH . $rIP . '_' . $rFloodType;
                        if (file_exists($rIPFile)) {
                            $rFloodRow = json_decode(file_get_contents($rIPFile), true);
                            $rFloodSeconds = intval(ipTV_lib::$settings['bruteforce_frequency']);
                            $rFloodLimit = intval(ipTV_lib::$settings[array('mac' => 'bruteforce_mac_attempts', 'user' => 'bruteforce_username_attempts')[$rFloodType]]);
                            $rFloodRow['attempts'] = truncateAttempts($rFloodRow['attempts'], $rFloodSeconds);
                            if (!in_array($rTerm, array_keys($rFloodRow['attempts']))) {
                                $rFloodRow['attempts'][$rTerm] = time();
                                if ($rFloodLimit > count($rFloodRow['attempts'])) {
                                    file_put_contents($rIPFile, json_encode($rFloodRow), LOCK_EX);
                                } else {
                                    if (!in_array($rIP, ipTV_lib::$blockedIPs)) {
                                        if (ipTV_lib::$cached) {
                                            ipTV_lib::setSignal('bruteforce_attack/' . $rIP, 1);
                                        } else {
                                            $ipTV_db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $rIP, 'BRUTEFORCE ' . strtoupper($rFloodType) . ' ATTACK', time());
                                        }
                                        touch(FLOOD_TMP_PATH . 'block_' . $rIP);
                                    }
                                    ipTV_lib::unlinkFile($rIPFile);
                                    return null;
                                }
                            }
                        } else {
                            $rFloodRow = array('attempts' => array($rTerm => time()));
                            file_put_contents($rIPFile, json_encode($rFloodRow), LOCK_EX);
                        }
                    } else {
                        return null;
                    }
                } else {
                    return null;
                }
            } else {
                return null;
            }
        } else {
            return null;
        }
    } else {
        return null;
    }
}
/**
 * Truncates the attempts based on a given frequency.
 *
 * This function takes an array of attempts and a frequency value as input.
 * It checks if the time difference between the current time and each attempt time is less than the given frequency.
 * If the $rList parameter is true, it iterates through the attempt times directly.
 * If $rList is false, it iterates through the attempts as key-value pairs.
 * It returns an array of allowed attempts that meet the frequency criteria.
 *
 * @param array $rAttempts An array of attempt times or key-value pairs.
 * @param int $rFrequency The time frequency in seconds to compare against.
 * @param bool $rList (Optional) If true, iterates through attempts directly; otherwise, iterates through key-value pairs.
 * @return array An array containing the allowed attempts based on the frequency criteria.
 */
function truncateAttempts($rAttempts, $rFrequency, $rList = false) {
    $rAllowedAttempts = array();
    $rTime = time();
    if ($rList) {
        foreach ($rAttempts as $rAttemptTime) {
            if ($rTime - $rAttemptTime < $rFrequency) {
                $rAllowedAttempts[] = $rAttemptTime;
            }
        }
    } else {
        foreach ($rAttempts as $rAttempt => $rAttemptTime) {
            if ($rTime - $rAttemptTime < $rFrequency) {
                $rAllowedAttempts[$rAttempt] = $rAttemptTime;
            }
        }
    }
    return $rAllowedAttempts;
}

function generateUniqueCode() {
    return substr(md5(ipTV_lib::$settings['live_streaming_pass']), 0, 15);
}
function generatePlaylist($rUserInfo, $rDeviceKey, $rOutputKey = 'ts', $rTypeKey = null, $rNoCache = false) {
    global $ipTV_db;
    if (!empty($rDeviceKey)) {
        if ($rOutputKey == 'mpegts') {
            $rOutputKey = 'ts';
        }
        if ($rOutputKey == 'hls') {
            $rOutputKey = 'm3u8';
        }
        if (empty($rOutputKey)) {
            $ipTV_db->query('SELECT t1.output_ext FROM `access_output` t1 INNER JOIN `devices` t2 ON t2.default_output = t1.access_output_id AND `device_key` = ?', $rDeviceKey);
        } else {
            $ipTV_db->query('SELECT t1.output_ext FROM `access_output` t1 WHERE `output_key` = ?', $rOutputKey);
        }
        if ($ipTV_db->num_rows() > 0) {
            $rCacheName = $rUserInfo['id'] . '_' . $rDeviceKey . '_' . $rOutputKey . '_' . implode('_', ($rTypeKey ?: array()));
            $rOutputExt = $ipTV_db->get_row()["output_ext"];
            $rEncryptPlaylist = ($rUserInfo['is_restreamer'] ? ipTV_lib::$settings['encrypt_playlist_restreamer'] : ipTV_lib::$settings['encrypt_playlist']);
            if ($rUserInfo['is_stalker']) {
                $rEncryptPlaylist = false;
            }
            $rDomainName = getDomainName();
            if ($rDomainName) {
                $rRTMPRows = array();
                if ($rOutputKey == 'rtmp') {
                    $ipTV_db->query('SELECT t1.id,t2.server_id FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id WHERE t1.rtmp_output = 1');
                    $rRTMPRows = $ipTV_db->get_rows(true, 'id', false, 'server_id');
                }
                if (empty($rOutputExt)) {
                    $rOutputExt = 'ts';
                }
                $ipTV_db->query('SELECT t1.*,t2.* FROM `devices` t1 LEFT JOIN `access_output` t2 ON t2.access_output_id = t1.default_output WHERE t1.device_key = ? LIMIT 1', $rDeviceKey);
                if (0 >= $ipTV_db->num_rows()) {
                    return false;
                }
                $rDeviceInfo = $ipTV_db->get_row();
                if (strlen($rUserInfo['access_token']) == 32) {
                    $rFilename = str_replace('{USERNAME}', $rUserInfo['access_token'], $rDeviceInfo['device_filename']);
                } else {
                    $rFilename = str_replace('{USERNAME}', $rUserInfo['username'], $rDeviceInfo['device_filename']);
                }
                if (!(0 < ipTV_lib::$settings['cache_playlists'] && !$rNoCache && file_exists(PLAYLIST_PATH . md5($rCacheName)))) {
                    $rData = '';
                    $rSeriesAllocation = $rSeriesEpisodes = $rSeriesInfo = array();
                    $rUserInfo['episode_ids'] = array();
                    if (count($rUserInfo['series_ids']) > 0) {
                        if (ipTV_lib::$cached) {
                            foreach ($rUserInfo['series_ids'] as $rSeriesID) {
                                $rSeriesInfo[$rSeriesID] = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_' . intval($rSeriesID)));
                                $rSeriesData = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'episodes_' . intval($rSeriesID)));
                                foreach ($rSeriesData as $rSeasonID => $rEpisodes) {
                                    foreach ($rEpisodes as $rEpisode) {
                                        $rSeriesEpisodes[$rEpisode['stream_id']] = array($rSeasonID, $rEpisode['episode_num']);
                                        $rSeriesAllocation[$rEpisode['stream_id']] = $rSeriesID;
                                        $rUserInfo['episode_ids'][] = $rEpisode['stream_id'];
                                    }
                                }
                            }
                        } else {
                            $ipTV_db->query('SELECT * FROM `streams_series` WHERE `id` IN (' . implode(',', $rUserInfo['series_ids']) . ')');
                            $rSeriesInfo = $ipTV_db->get_rows(true, 'id');
                            if (count($rUserInfo['series_ids']) > 0) {
                                $ipTV_db->query('SELECT stream_id, series_id, season_num, episode_num FROM `streams_episodes` WHERE series_id IN (' . implode(',', $rUserInfo['series_ids']) . ') ORDER BY FIELD(series_id,' . implode(',', $rUserInfo['series_ids']) . '), season_num ASC, episode_num ASC');
                                foreach ($ipTV_db->get_rows(true, 'series_id', false) as $rSeriesID => $rEpisodes) {
                                    foreach ($rEpisodes as $rEpisode) {
                                        $rSeriesEpisodes[$rEpisode['stream_id']] = array($rEpisode['season_num'], $rEpisode['episode_num']);
                                        $rSeriesAllocation[$rEpisode['stream_id']] = $rSeriesID;
                                        $rUserInfo['episode_ids'][] = $rEpisode['stream_id'];
                                    }
                                }
                            }
                        }
                    }
                    if (count($rUserInfo['episode_ids']) > 0) {
                        $rUserInfo['channel_ids'] = array_merge($rUserInfo['channel_ids'], $rUserInfo['episode_ids']);
                    }
                    $rChannelIDs = array();
                    $rAdded = false;
                    if ($rTypeKey) {
                        foreach ($rTypeKey as $rType) {
                            switch ($rType) {
                                case 'live':
                                case 'created_live':
                                    if (!$rAdded) {
                                        $rChannelIDs = array_merge($rChannelIDs, $rUserInfo['live_ids']);
                                        $rAdded = true;
                                        break;
                                    }
                                    break;
                                case 'movie':
                                    $rChannelIDs = array_merge($rChannelIDs, $rUserInfo['vod_ids']);
                                    break;
                                case 'radio_streams':
                                    $rChannelIDs = array_merge($rChannelIDs, $rUserInfo['radio_ids']);
                                    break;
                                case 'series':
                                    $rChannelIDs = array_merge($rChannelIDs, $rUserInfo['episode_ids']);
                                    break;
                            }
                        }
                    } else {
                        $rChannelIDs = $rUserInfo['channel_ids'];
                    }
                    if (in_array(ipTV_lib::$settings['channel_number_type'], array('bouquet_new', 'manual'))) {
                        $rChannelIDs = ipTV_lib::sortChannels($rChannelIDs);
                    }
                    unset($rUserInfo['live_ids'], $rUserInfo['vod_ids'], $rUserInfo['radio_ids'], $rUserInfo['episode_ids'], $rUserInfo['channel_ids']);
                    $rOutputFile = null;
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/octet-stream');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    if (strlen($rUserInfo['access_token']) == 32) {
                        header('Content-Disposition: attachment; filename="' . str_replace('{USERNAME}', $rUserInfo['access_token'], $rDeviceInfo['device_filename']) . '"');
                    } else {
                        header('Content-Disposition: attachment; filename="' . str_replace('{USERNAME}', $rUserInfo['username'], $rDeviceInfo['device_filename']) . '"');
                    }
                    if (ipTV_lib::$settings['cache_playlists'] > 0) {
                        $rOutputPath = PLAYLIST_PATH . md5($rCacheName) . '.write';
                        $rOutputFile = fopen($rOutputPath, 'w');
                    }
                    if ($rDeviceKey == 'starlivev5') {
                        $rOutput = array();
                        $rOutput['iptvstreams_list'] = array();
                        $rOutput['iptvstreams_list']['@version'] = 1;
                        $rOutput['iptvstreams_list']['group'] = array();
                        $rOutput['iptvstreams_list']['group']['name'] = 'IPTV';
                        $rOutput['iptvstreams_list']['group']['channel'] = array();
                        foreach (array_chunk($rChannelIDs, 1000) as $rBlockIDs) {
                            if (ipTV_lib::$settings['playlist_from_mysql'] || !ipTV_lib::$cached) {
                                $rOrder = 'FIELD(`t1`.`id`,' . implode(',', $rBlockIDs) . ')';
                                $ipTV_db->query('SELECT t1.id,t1.channel_id,t1.year,t1.movie_properties,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t2.type_output,t2.type_key,t1.target_container,t2.live FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type WHERE `t1`.`id` IN (' . implode(',', array_map('intval', $rBlockIDs)) . ') ORDER BY ' . $rOrder . ';');
                                $rRows = $ipTV_db->get_rows();
                            } else {
                                $rRows = array();
                                foreach ($rBlockIDs as $rID) {
                                    $rRows[] = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rID)))['info'];
                                }
                            }
                            foreach ($rRows as $rChannelInfo) {
                                if (!$rTypeKey || in_array($rChannelInfo['type_output'], $rTypeKey)) {
                                    if (!$rChannelInfo['target_container']) {
                                        $rChannelInfo['target_container'] = 'mp4';
                                    }
                                    $rProperties = (!is_array($rChannelInfo['movie_properties']) ? json_decode($rChannelInfo['movie_properties'], true) : $rChannelInfo['movie_properties']);
                                    if ($rChannelInfo['type_key'] == 'series') {
                                        $rSeriesID = $rSeriesAllocation[$rChannelInfo['id']];
                                        $rChannelInfo['live'] = 0;
                                        $rChannelInfo['stream_display_name'] = $rSeriesInfo[$rSeriesID]['title'] . ' S' . sprintf('%02d', $rSeriesEpisodes[$rChannelInfo['id']][0]) . 'E' . sprintf('%02d', $rSeriesEpisodes[$rChannelInfo['id']][1]);
                                        $rChannelInfo['movie_properties'] = array('movie_image' => (!empty($rProperties['movie_image']) ? $rProperties['movie_image'] : $rSeriesInfo['cover']));
                                        $rChannelInfo['type_output'] = 'series';
                                        $rChannelInfo['category_id'] = $rSeriesInfo[$rSeriesID]['category_id'];
                                    }
                                    if (strlen($rUserInfo['access_token']) == 32) {
                                        $rURL = $rDomainName . $rChannelInfo['type_output'] . '/' . $rUserInfo['access_token'] . '/';
                                        if ($rChannelInfo['live'] == 0) {
                                            $rURL .= $rChannelInfo['id'] . '.' . $rChannelInfo['target_container'];
                                        } elseif (ipTV_lib::$settings['cloudflare'] && $rOutputExt == 'ts') {
                                            $rURL .= $rChannelInfo['id'];
                                        } else {
                                            $rURL .= $rChannelInfo['id'] . '.' . $rOutputExt;
                                        }
                                    } else {
                                        if ($rEncryptPlaylist) {
                                            $rEncData = $rChannelInfo['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/';
                                            if ($rChannelInfo['live'] == 0) {
                                                $rEncData .= $rChannelInfo['id'] . '/' . $rChannelInfo['target_container'];
                                            } elseif (ipTV_lib::$settings['cloudflare'] && $rOutputExt == 'ts') {
                                                $rEncData .= $rChannelInfo['id'];
                                            } else {
                                                $rEncData .= $rChannelInfo['id'] . '/' . $rOutputExt;
                                            }
                                            $rToken = encryptData($rEncData, ipTV_lib::$settings['live_streaming_pass'], OPENSSL_EXTRA);
                                            $rURL = $rDomainName . 'play/' . $rToken;
                                            if ($rChannelInfo['live'] == 0) {
                                                $rURL .= '#.' . $rChannelInfo['target_container'];
                                            }
                                        } else {
                                            $rURL = $rDomainName . $rChannelInfo['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/';
                                            if ($rChannelInfo['live'] == 0) {
                                                $rURL .= $rChannelInfo['id'] . '.' . $rChannelInfo['target_container'];
                                            } elseif (ipTV_lib::$settings['cloudflare'] && $rOutputExt == 'ts') {
                                                $rURL .= $rChannelInfo['id'];
                                            } else {
                                                $rURL .= $rChannelInfo['id'] . '.' . $rOutputExt;
                                            }
                                        }
                                    }
                                    if ($rChannelInfo['live'] == 0) {
                                        if (!empty($rProperties['movie_image'])) {
                                            $rIcon = $rProperties['movie_image'];
                                        }
                                    } else {
                                        $rIcon = $rChannelInfo['stream_icon'];
                                    }
                                    $rChannel = array();
                                    $rChannel['name'] = $rChannelInfo['stream_display_name'];
                                    $rChannel['icon'] = $rIcon;
                                    $rChannel['stream_url'] = $rURL;
                                    $rChannel['stream_type'] = 0;
                                    $rOutput['iptvstreams_list']['group']['channel'][] = $rChannel;
                                }
                            }
                            unset($rRows);
                        }
                        $rData = json_encode((object) $rOutput);
                    } else {
                        if (!empty($rDeviceInfo['device_header'])) {
                            $rAppend = ($rDeviceInfo['device_header'] == '#EXTM3U' ? "\n" . '#EXT-X-SESSION-DATA:DATA-ID="XC_VM.' . str_replace('.', '_', SCRIPT_VERSION) . '"' : '');
                            $rData = str_replace(array('&lt;', '&gt;'), array('<', '>'), str_replace(array('{BOUQUET_NAME}', '{USERNAME}', '{PASSWORD}', '{SERVER_URL}', '{OUTPUT_KEY}'), array(ipTV_lib::$settings['server_name'], $rUserInfo['username'], $rUserInfo['password'], $rDomainName, $rOutputKey), $rDeviceInfo['device_header'] . $rAppend)) . "\n";
                            if ($rOutputFile) {
                                fwrite($rOutputFile, $rData);
                            }
                            echo $rData;
                            unset($rData);
                        }
                        if (!empty($rDeviceInfo['device_conf'])) {
                            if (preg_match('/\\{URL\\#(.*?)\\}/', $rDeviceInfo['device_conf'], $rMatches)) {
                                $rCharts = str_split($rMatches[1]);
                                $rPattern = $rMatches[0];
                            } else {
                                $rCharts = array();
                                $rPattern = '{URL}';
                            }
                            foreach (array_chunk($rChannelIDs, 1000) as $rBlockIDs) {
                                if (ipTV_lib::$settings['playlist_from_mysql'] || !ipTV_lib::$cached) {
                                    $rOrder = 'FIELD(`t1`.`id`,' . implode(',', $rBlockIDs) . ')';
                                    $ipTV_db->query('SELECT t1.id,t1.channel_id,t1.year,t1.movie_properties,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t2.type_output,t2.type_key,t1.target_container,t2.live,t1.tv_archive_duration,t1.tv_archive_server_id FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type WHERE `t1`.`id` IN (' . implode(',', array_map('intval', $rBlockIDs)) . ') ORDER BY ' . $rOrder . ';');
                                    $rRows = $ipTV_db->get_rows();
                                } else {
                                    $rRows = array();
                                    foreach ($rBlockIDs as $rID) {
                                        $rRows[] = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rID)))['info'];
                                    }
                                }
                                foreach ($rRows as $rChannel) {
                                    if (!empty($rTypeKey) && in_array($rChannel['type_output'], $rTypeKey)) {
                                        if (!$rChannel['target_container']) {
                                            $rChannel['target_container'] = 'mp4';
                                        }
                                        $rConfig = $rDeviceInfo['device_conf'];
                                        if ($rDeviceInfo['device_key'] == 'm3u_plus') {
                                            if (!$rChannel['live']) {
                                                $rConfig = str_replace('tvg-id="{CHANNEL_ID}" ', '', $rConfig);
                                            }
                                            if (!$rEncryptPlaylist) {
                                                $rConfig = str_replace('xui-id="{XUI_ID}" ', '', $rConfig);
                                            }
                                            if (0 < $rChannel['tv_archive_server_id'] && 0 < $rChannel['tv_archive_duration']) {
                                                $rConfig = str_replace('#EXTINF:-1 ', '#EXTINF:-1 timeshift="' . intval($rChannel['tv_archive_duration']) . '" ', $rConfig);
                                            }
                                        }
                                        $rProperties = (!is_array($rChannel['movie_properties']) ? json_decode($rChannel['movie_properties'], true) : $rChannel['movie_properties']);
                                        if ($rChannel['type_key'] == 'series') {
                                            $rSeriesID = $rSeriesAllocation[$rChannel['id']];
                                            $rChannel['live'] = 0;
                                            $rChannel['stream_display_name'] = $rSeriesInfo[$rSeriesID]['title'] . ' S' . sprintf('%02d', $rSeriesEpisodes[$rChannel['id']][0]) . 'E' . sprintf('%02d', $rSeriesEpisodes[$rChannel['id']][1]);
                                            $rChannel['movie_properties'] = array('movie_image' => (!empty($rProperties['movie_image']) ? $rProperties['movie_image'] : $rSeriesInfo['cover']));
                                            $rChannel['type_output'] = 'series';
                                            $rChannel['category_id'] = $rSeriesInfo[$rSeriesID]['category_id'];
                                        }
                                        if ($rChannel['live'] == 0) {
                                            if (strlen($rUserInfo['access_token']) == 32) {
                                                $rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['access_token'] . '/' . $rChannel['id'] . '.' . $rChannel['target_container'];
                                            } else {
                                                if ($rEncryptPlaylist) {
                                                    $rEncData = $rChannel['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'] . '/' . $rChannel['target_container'];
                                                    $rToken = encryptData($rEncData, ipTV_lib::$settings['live_streaming_pass'], OPENSSL_EXTRA);
                                                    $rURL = $rDomainName . 'play/' . $rToken . '#.' . $rChannel['target_container'];
                                                } else {
                                                    $rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'] . '.' . $rChannel['target_container'];
                                                }
                                            }
                                            if (!empty($rProperties['movie_image'])) {
                                                $rIcon = $rProperties['movie_image'];
                                            }
                                        } else {
                                            if ($rOutputKey != 'rtmp' || !array_key_exists($rChannel['id'], $rRTMPRows)) {
                                                if (strlen($rUserInfo['access_token']) == 32) {
                                                    if (ipTV_lib::$settings['cloudflare'] && $rOutputExt == 'ts') {
                                                        $rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['access_token'] . '/' . $rChannel['id'];
                                                    } else {
                                                        $rURL = $rDomainName . $rChannel['type_output'] . '/' . $rUserInfo['access_token'] . '/' . $rChannel['id'] . '.' . $rOutputExt;
                                                    }
                                                } elseif ($rEncryptPlaylist) {
                                                    $rEncData = $rChannel['type_output'] . '/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'];
                                                    $rToken = encryptData($rEncData, ipTV_lib::$settings['live_streaming_pass'], OPENSSL_EXTRA);
                                                    if (ipTV_lib::$settings['cloudflare'] && $rOutputExt == 'ts') {
                                                        $rURL = $rDomainName . 'play/' . $rToken;
                                                    } else {
                                                        $rURL = $rDomainName . 'play/' . $rToken . '/' . $rOutputExt;
                                                    }
                                                } else {
                                                    if (ipTV_lib::$settings['cloudflare'] && $rOutputExt == 'ts') {
                                                        $rURL = $rDomainName . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'];
                                                    } else {
                                                        $rURL = $rDomainName . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rChannel['id'] . '.' . $rOutputExt;
                                                    }
                                                }
                                            } else {
                                                $rAvailableServers = array_values(array_keys($rRTMPRows[$rChannel['id']]));
                                                if (in_array($rUserInfo['force_server_id'], $rAvailableServers)) {
                                                    $rServerID = $rUserInfo['force_server_id'];
                                                } else {
                                                    if (ipTV_lib::$settings['rtmp_random'] == 1) {
                                                        $rServerID = $rAvailableServers[array_rand($rAvailableServers, 1)];
                                                    } else {
                                                        $rServerID = $rAvailableServers[0];
                                                    }
                                                }
                                                if (strlen($rUserInfo['access_token']) == 32) {
                                                    $rURL = ipTV_lib::$Servers[$rServerID]['rtmp_server'] . $rChannel['id'] . '?token=' . $rUserInfo['access_token'];
                                                } else {
                                                    if ($rEncryptPlaylist) {
                                                        $rEncData = $rUserInfo['username'] . '/' . $rUserInfo['password'];
                                                        $rToken = encryptData($rEncData, ipTV_lib::$settings['live_streaming_pass'], OPENSSL_EXTRA);
                                                        $rURL = ipTV_lib::$Servers[$rServerID]['rtmp_server'] . $rChannel['id'] . '?token=' . $rToken;
                                                    } else {
                                                        $rURL = ipTV_lib::$Servers[$rServerID]['rtmp_server'] . $rChannel['id'] . '?username=' . $rUserInfo['username'] . '&password=' . $rUserInfo['password'];
                                                    }
                                                }
                                            }
                                            $rIcon = $rChannel['stream_icon'];
                                        }
                                        $rESRID = ($rChannel['live'] == 1 ? 1 : 4097);
                                        $rSID = (!empty($rChannel['custom_sid']) ? $rChannel['custom_sid'] : ':0:1:0:0:0:0:0:0:0:');
                                        $rCategoryIDs = json_decode($rChannel['category_id'], true);
                                        if (count($rCategoryIDs) > 0) {
                                            foreach ($rCategoryIDs as $rCategoryID) {
                                                if (isset(ipTV_lib::$categories[$rCategoryID])) {
                                                    $rData = str_replace(array('&lt;', '&gt;'), array('<', '>'), str_replace(array($rPattern, '{ESR_ID}', '{SID}', '{CHANNEL_NAME}', '{CHANNEL_ID}', '{XUI_ID}', '{CATEGORY}', '{CHANNEL_ICON}'), array(str_replace($rCharts, array_map('urlencode', $rCharts), $rURL), $rESRID, $rSID, $rChannel['stream_display_name'], $rChannel['channel_id'], $rChannel['id'], ipTV_lib::$categories[$rCategoryID]['category_name'], $rIcon), $rConfig)) . "\r\n";
                                                    if ($rOutputFile) {
                                                        fwrite($rOutputFile, $rData);
                                                    }
                                                    echo $rData;
                                                    unset($rData);
                                                    // if (stripos($rDeviceInfo['device_conf'], '{CATEGORY}') == false) {
                                                    //     break;
                                                    // }
                                                }
                                            }
                                        } else {
                                            $rData = str_replace(array('&lt;', '&gt;'), array('<', '>'), str_replace(array($rPattern, '{ESR_ID}', '{SID}', '{CHANNEL_NAME}', '{CHANNEL_ID}', '{XUI_ID}', '{CHANNEL_ICON}'), array(str_replace($rCharts, array_map('urlencode', $rCharts), $rURL), $rESRID, $rSID, $rChannel['stream_display_name'], $rChannel['channel_id'], $rChannel['id'], $rIcon), $rConfig)) . "\r\n";
                                            if ($rOutputFile) {
                                                fwrite($rOutputFile, $rData);
                                            }
                                            echo $rData;
                                            unset($rData);
                                        }
                                    }
                                }
                                unset($rRows);
                            }
                            $rData = trim(str_replace(array('&lt;', '&gt;'), array('<', '>'), $rDeviceInfo['device_footer']));
                            if ($rOutputFile) {
                                fwrite($rOutputFile, $rData);
                            }
                            echo $rData;
                            unset($rData);
                        }
                    }
                    if ($rOutputFile) {
                        fclose($rOutputFile);
                        rename(PLAYLIST_PATH . md5($rCacheName) . '.write', PLAYLIST_PATH . md5($rCacheName));
                    }
                    exit();
                } else {
                    header('Content-Description: File Transfer');
                    header('Content-Type: audio/mpegurl');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    header('Content-Disposition: attachment; filename="' . $rFilename . '"');
                    header('Content-Length: ' . filesize(PLAYLIST_PATH . md5($rCacheName)));
                    readfile(PLAYLIST_PATH . md5($rCacheName));
                    exit();
                }
            } else {
                exit();
            }
        } else {
            return false;
        }
    } else {
        return false;
    }
}

function getUptime() {
    if (!(file_exists('/proc/uptime') && is_readable('/proc/uptime'))) {
        return '';
    }
    $tmp = explode(' ', file_get_contents('/proc/uptime'));
    return secondsToTime(intval($tmp[0]));
}
function secondsToTime($inputSeconds) {
    $secondsInAMinute = 60;
    $secondsInAnHour = 60 * $secondsInAMinute;
    $secondsInADay = 24 * $secondsInAnHour;
    $days = (int) floor($inputSeconds / $secondsInADay);
    $hourSeconds = $inputSeconds % $secondsInADay;
    $hours = (int) floor($hourSeconds / $secondsInAnHour);
    $minuteSeconds = $hourSeconds % $secondsInAnHour;
    $minutes = (int) floor($minuteSeconds / $secondsInAMinute);
    $remainingSeconds = $minuteSeconds % $secondsInAMinute;
    $seconds = (int) ceil($remainingSeconds);
    $final = '';
    if ($days != 0) {
        $final .= "{$days}d ";
    }
    if ($hours != 0) {
        $final .= "{$hours}h ";
    }
    if ($minutes != 0) {
        $final .= "{$minutes}m ";
    }
    $final .= "{$seconds}s";
    return $final;
}
/**
 * Encrypts the provided data using AES-256-CBC encryption with a given decryption key and device ID.
 *
 * @param string $rData The data to be encrypted.
 * @param string $decryptionKey The decryption key used to encrypt the data.
 * @param string $rDeviceID The device ID used in the encryption process.
 * @return string The encrypted data in base64url encoding.
 */
function encryptData($rData, $decryptionKey, $rDeviceID) {
    return base64url_encode(openssl_encrypt($rData, 'aes-256-cbc', md5(sha1($rDeviceID) . $decryptionKey), OPENSSL_RAW_DATA, substr(md5(sha1($decryptionKey)), 0, 16)));
}
/**
 * Decrypts the provided data using AES-256-CBC decryption with a given decryption key and device ID.
 *
 * @param string $rData The data to be decrypted.
 * @param string $decryptionKey The decryption key used to decrypt the data.
 * @param string $rDeviceID The device ID used in the decryption process.
 * @return string The decrypted data.
 */
function decryptData($rData, $decryptionKey, $rDeviceID) {
    return openssl_decrypt(base64url_decode($rData), 'aes-256-cbc', md5(sha1($rDeviceID) . $decryptionKey), OPENSSL_RAW_DATA, substr(md5(sha1($decryptionKey)), 0, 16));
}
/**
 * Encodes the input data using base64url encoding.
 *
 * This function takes the input data and encodes it using base64 encoding. It then replaces the characters '+' and '/' with '-' and '_', respectively, to make the encoding URL-safe. Finally, it removes any padding '=' characters at the end of the encoded string.
 *
 * @param string $rData The input data to be encoded.
 * @return string The base64url encoded string.
 */
function base64url_encode($rData) {
    return rtrim(strtr(base64_encode($rData), '+/', '-_'), '=');
}
/**
 * Decodes the input data encoded using base64url encoding.
 *
 * This function takes the input data encoded using base64url encoding and decodes it. It first replaces the characters '-' and '_' back to '+' and '/' respectively, to revert the URL-safe encoding. Then, it decodes the base64 encoded string to retrieve the original data.
 *
 * @param string $rData The base64url encoded data to be decoded.
 * @return string|false The decoded original data, or false if decoding fails.
 */
function base64url_decode($rData) {
    return base64_decode(strtr($rData, '-_', '+/'));
}

function getDomainName($rForceSSL = false) {
    $rDomainName = null;
    $rKey = ($rForceSSL ? 'https_url' : 'site_url');

    if (ipTV_lib::$settings['use_mdomain_in_lists'] == 1) {
        $rDomainName = ipTV_lib::$Servers[SERVER_ID][$rKey];
    } else {
        list($serverIPAddress, $serverPort) = explode(':', $_SERVER['HTTP_HOST']);
        if ($rForceSSL) {
            $rDomainName = 'https://' . $serverIPAddress . ':' . ipTV_lib::$Servers[SERVER_ID]['https_broadcast_port'] . '/';
        } else {
            $rDomainName = ipTV_lib::$Servers[SERVER_ID]['server_protocol'] . '://' . $serverIPAddress . ':' . ipTV_lib::$Servers[SERVER_ID]['request_port'] . '/';
        }
    }
    return $rDomainName;
}
