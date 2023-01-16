<?php
/*
Plugin Name:  WP-CLI Sync
Description:  A WP-CLI command for syncing a remote site to a local environment
Version:      1.3.1
Author:       Jon Beaumont-Pike
Author URI:   https://jonbp.co.uk/
License:      MIT License
*/

// Set Default Vars
$env_variables = array(
  'REMOTE_SSH_HOSTNAME',
  'REMOTE_SSH_USERNAME',
  'REMOTE_PROJECT_DIR',
  'REMOTE_UPLOAD_DIR',
  'REMOTE_PORT',
  'LOCAL_ACTIVATED_PLUGINS',
  'LOCAL_DEACTIVATED_PLUGINS',
  'LOCAL_POST_SYNC_QUERIES',
  'LOCAL_SYNC_DIR_EXCLUDES',
  'LOCAL_TASK_DEBUG'
);

foreach($env_variables as $env_variable) {
    $_ENV[$env_variable] = getenv($env_variable) ?: '';
}

// Define Sync Command
if ( defined( 'WP_CLI' ) && WP_CLI ) {
  $sync = function($args) {

    if ($args) {
      $remote_environment = strtoupper($args[0]);
      $_ENV['REMOTE_SSH_HOSTNAME'] = getenv('REMOTE_SSH_HOSTNAME_' . $remote_environment);
      $_ENV['REMOTE_SSH_USERNAME'] = getenv('REMOTE_SSH_USERNAME_' . $remote_environment);
      $_ENV['REMOTE_PROJECT_DIR'] = getenv('REMOTE_PROJECT_DIR_' . $remote_environment);
      $_ENV['REMOTE_UPLOAD_DIR'] = getenv('REMOTE_UPLOAD_DIR_' . $remote_environment);
    }

    // Task Message
    function task_message($message, $title='Task', $color = 34, $firstBreak = true) {
      if($firstBreak == true) {
        echo "\n";
      }
      echo "\033[".$color."m".$title.": ".$message."\n\033[0m";
    }

    // Debug Message
    function debug_message($message, $title='Debug', $color = 33, $firstBreak = false) {
      if (empty($_ENV['LOCAL_TASK_DEBUG'])) {
        return;
      }
      if ($firstBreak == true) {
        echo "\n";
      }
      echo "\033[".$color."m".$title.": ".$message."\n\033[0m";
    }

    // Line Break + Color Reset
    function lb_cr() {
      echo "\n\033[0m";
    }

    // Fail Count Var
    $fail_count = 0;

    // Sync vars
    $ssh_hostname = $_ENV['REMOTE_SSH_HOSTNAME'];
    $ssh_username = $_ENV['REMOTE_SSH_USERNAME'];
    $rem_proj_loc = $_ENV['REMOTE_PROJECT_DIR'];
    $ssh_port = $_ENV['REMOTE_PORT'] ?? false;
    $ssh_port_command = $ssh_port ? ' -p ' . $ssh_port . ' ' : '';
    $rsync_port_command = $ssh_port ? ' -e "ssh -p 18765" ' : '';

    // Welcome
    task_message('Running .env file and connection checks...', 'WP-CLI Sync', 97);

    /**
     * BEGIN VAR / CONNECTION CHECKS
     */

    // Exit if some vars missing
    if (empty($ssh_hostname) || empty($ssh_username) || empty($rem_proj_loc)) {

      // Exit Messages
      task_message('some/all local sync vars are not set in .env file', 'Error', 31, false);

      // Line Break + Color Reset + Exit
      lb_cr();
      exit();

    }

    // Check if Remote location formatted correctly
    if(($rem_proj_loc[0] != '/') && ($rem_proj_loc[0] != '~')) {

      // Exit Messages
      task_message('Incorrect formatting of the REMOTE_PROJECT_DIR variable', 'Error', 31, false);
      task_message('Ensure that the path begins with either / or ~/', 'Hint', 33);

      // Line Break + Color Reset + Exit
      lb_cr();
      exit();

    } elseif($rem_proj_loc[0] == '~') {

      if($rem_proj_loc[1] != '/') {

        // Exit Messages
        task_message('Incorrect formatting of the REMOTE_PROJECT_DIR variable', 'Error', 31, false);
        task_message('Ensure that the path begins with either / or ~/', 'Hint', 33);

        // Line Break + Color Reset + Exit
        lb_cr();
        exit();

      }

    }

    // Check if SSH connection works
    $command = 'ssh ' . $ssh_port_command . ' -q '.$ssh_username.'@'.$ssh_hostname.' exit; echo $?';
    $remote_server_status = exec($command);

    if ($remote_server_status == '255') {

      // Exit Messages
      task_message('Cannot connect to remote server over SSH', 'Error', 31, false);
      task_message('Check that your REMOTE_SSH_HOSTNAME and REMOTE_SSH_USERNAME variables are correct', 'Hint', 33);

      // Line Break + Color Reset + Exit
      lb_cr();
      exit();

    }

    // Check if WP-CLI is installed on remote server
    $command = 'ssh ' . $ssh_port_command. ' -q '.$ssh_username.'@'.$ssh_hostname.' "bash -ci \"cd '.$rem_proj_loc.' && type wp && echo true || echo false\""';
    $remote_server_check = exec($command);

    if ($remote_server_check == 'false') {

      // Exit Messages
      task_message('Connected but cannot find remote WP-CLI', 'Error', 31, false);
      task_message('Either WP-CLI Sync is not installed on the remote server or the REMOTE_PROJECT_DIR variable is incorrect', 'Hint', 33);

      // Line Break + Color Reset + Exit
      lb_cr();
      exit();

    }

    // Checks Success
    task_message('Running sync...', 'Connected', 32, false);

    // Plugin Vars
    $local_activated_plugins = $_ENV['LOCAL_ACTIVATED_PLUGINS'];
    $local_deactivated_plugins = $_ENV['LOCAL_DEACTIVATED_PLUGINS'];

    // Activate Maintenance Mode
    $command = 'wp maintenance-mode activate';
    exec($command);

    /**
     * TASK: Database Sync
     */
    $task_name = 'Sync Database';
    task_message($task_name);

    // pv check
    if (`which pv`) {
      $pipe = '| pv |';
    } else {
      task_message('Install the \'pv\' command to monitor import progress', 'Notice', 33, false);
      $pipe = '|';
    }

    $command = 'ssh ' . $ssh_port_command . ' ' . $ssh_username.'@'.$ssh_hostname.' "bash -ci \"cd '.$rem_proj_loc.' && wp db export --single-transaction -\"" '.$pipe. ' wp db import -';
    debug_message($command);
    system($command);

    /**
     * TASK: Post sync queries
     */
    if ($queries = $_ENV['LOCAL_POST_SYNC_QUERIES']) {
      $command = 'wp db query "' . preg_replace('/(`|")/i', '\\\\${1}', $queries) . '"';
      debug_message($command);
      system($command);
    }


    /**
     * TASK: Sync Uploads Folder
     */
    $task_name = 'Sync Uploads Folder';

    $excludes  = '';
    if ($exclude_dirs = $_ENV['LOCAL_SYNC_DIR_EXCLUDES']) {
      $exclude_dirs = explode(',', $exclude_dirs);
      foreach ($exclude_dirs as $dir) {
        $excludes .= ' --exclude=' . $dir;
      }
    }

    $local_upload_dir = wp_get_upload_dir()['basedir'];
    $remote_upload_dir = $_ENV['REMOTE_UPLOAD_DIR'];

    if (`which rsync`) {
      task_message($task_name);
      $command = 'rsync ' . $rsync_port_command . ' -avhP '.$ssh_username.'@'.$ssh_hostname.':' . $remote_upload_dir . '/ ' . $local_upload_dir . '/' . $excludes;
      debug_message($command);
      system($command);
    } else {
      task_message($task_name.' task not ran, please install \'rsync\'', 'Error', 31);
      $fail_count++;
    }

    /**
     * TASK: Activate / Deactivate Plugins
     */

    // Activate Plugins
    if (!empty($local_activated_plugins)) {
      task_message('Activate Plugins');
      $cleaned_arr_list = preg_replace('/[ ,]+/', ' ', trim($local_activated_plugins));
      $command = 'wp plugin activate '.$cleaned_arr_list;
      debug_message($command);
      system($command);
    }

    // Deactivate Plugins
    if (!empty($local_deactivated_plugins)) {
      task_message('Deactivate Plugins');
      $cleaned_arr_list = preg_replace('/[ ,]+/', ' ', trim($local_deactivated_plugins));
      $command = 'wp plugin deactivate '.$cleaned_arr_list;
      debug_message($command);
      system($command);
    }

    // Deactivate Maintenance Mode
    $command = 'wp maintenance-mode deactivate';
    exec($command);

    // Completion Message
    if ($fail_count > 0) {
      task_message('Finished with '.$fail_count. ' errors', 'Warning', 33);
    } else {
      task_message('All Tasks Finished', 'Success', 32);
    }

    // Final Line Break + Color Reset
    lb_cr();

  };

  WP_CLI::add_command('sync', $sync);
}
