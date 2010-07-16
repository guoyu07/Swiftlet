<?php
/**
 * @package Swiftlet
 * @copyright 2009 ElbertF http://elbertf.com
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU Public License
 */

$controllerSetup = array(
	'rootPath'  => '../',
	'pageTitle' => 'Plugin installer'
	);

require($controllerSetup['rootPath'] . 'init.php');

$app->check_dependencies(array('buffer', 'input'));

$app->input->validate(array(
	'plugin'          => 'bool',
	'system-password' => '/^' . preg_quote($app->sysPassword, '/') . '$/',
	'mode'            => 'string',
	'form-submit'     => 'bool',
	));

$authenticated = isset($_SESSION['swiftlet authenticated']);

$view->newPlugins       = array();
$view->outdatedPlugins  = array();
$view->installedPlugins = array();

if ( isset($app->db) )
{
	$requiredBy = array();

	foreach ( $app->pluginsLoaded as $pluginName => $plugin )
	{
		foreach ( $plugin->info['dependencies'] as $dependency )
		{
			if ( !isset($requiredBy[$dependency]) )
			{
				$requiredBy[$dependency] = array();
			}

			$requiredBy[$dependency][$pluginName] = !empty($app->{$dependency}->ready) && $plugin->get_version() ? 1 : 0;
		}
	}

	foreach ( $app->pluginsLoaded as $pluginName => $plugin )
	{
		$version = $plugin->get_version();

		if ( !$version )
		{
			if ( isset($plugin->info['hooks']['install']) )
			{
				$dependencyStatus = array();

				foreach ( $plugin->info['dependencies'] as $dependency )
				{
					$dependencyStatus[$dependency] = !empty($app->{$dependency}->ready) ? 1 : 0;
				}

				$view->newPlugins[$pluginName]                      = $plugin->info;
				$view->newPlugins[$pluginName]['dependency_status'] = $dependencyStatus;
			}
		}
		else
		{
			if ( isset($plugin->info['hooks']['upgrade']) )
			{
				if ( version_compare($version, str_replace('*', '99999', $plugin->info['upgradable']['from']), '>=') && version_compare($version, str_replace('*', '99999', $plugin->info['upgradable']['to']), '<=') )
				{
					$view->outdatedPlugins[$pluginName] = $plugin->info;
				}
			}
			
			if ( ($plugin->info['hooks']['remove']) )
			{
				$view->installedPlugins[$pluginName]                       = $plugin->info;
				$view->installedPlugins[$pluginName]['required_by_status'] = isset($requiredBy[$pluginName]) ? $requiredBy[$pluginName] : array();
			}
		}
	}
}

ksort($view->newPlugins);

if ( !$app->sysPassword )
{
	$view->error = $view->t('%1$s has no value in %2$s (required).', array('<code>sysPassword</code>', '<code>/_config.php</code>'));
}
elseif ( empty($app->db->ready) )
{
	$view->error = $view->t('No database connected (required). You may need to change the database settings in %1$s.', '<code>/_config.php</code>');
}
else
{
	if ( $app->input->POST_valid['form-submit'] )
	{
		/*
		 * Delay the script to prevent brute-force attacks
		 */
		sleep(1);

		if ( $app->input->errors )
		{
			$view->error = $view->t('Incorrect system password.');
		}
		else
		{
			if ( $app->input->POST_raw['mode'] == 'authenticate' )
			{
				$_SESSION['swiftlet authenticated'] = TRUE;

				$authenticated = TRUE;
			}
			else if ( $authenticated && $app->input->POST_valid['plugin'] && is_array($app->input->POST_valid['plugin']) )
			{
				switch ( $app->input->POST_raw['mode'] )
				{
					case 'install': 
						/**
						 * Create plugin versions table
						 */			
						if ( !in_array($app->db->prefix . 'versions', $app->db->tables) )
						{
							$app->db->sql('
								CREATE TABLE `' . $app->db->prefix . 'versions` (
									`id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
									`plugin`      VARCHAR(256)     NOT NULL,
									`version`     VARCHAR(10)      NOT NULL,
									PRIMARY KEY (`id`)
									) TYPE = INNODB
								;');				
						}

						$pluginsInstalled = array();

						foreach ( $app->input->POST_valid['plugin'] as $pluginName => $v )
						{
							if ( isset($view->newPlugins[$pluginName]) && !in_array(0, $view->newPlugins[$pluginName]['dependency_status']) )
							{
								$app->pluginsLoaded[$pluginName]->install();

								$app->db->sql('
									INSERT INTO `' . $app->db->prefix . 'versions` (
										`plugin`,
										`version`
										)
									VALUES (
										"' . $app->db->escape($pluginName)           . '",
										"' . $view->newPlugins[$pluginName]['version'] . '"
										)
									;');

								$pluginsInstalled[] = $pluginName;

								unset($view->newPlugins[$pluginName]);
							}
						}

						if ( $pluginsInstalled )
						{
							header('Location: ?notice=installed&plugins=' . implode('|', $pluginsInstalled));

							$app->end();
						}
						
						break;
					case 'upgrade':
						$pluginsUpgraded = array();

						foreach ( $app->input->POST_valid['plugin'] as $pluginName => $v )
						{
							if ( isset($view->outdatedPlugins[$pluginName]) )
							{
								$app->pluginsLoaded[$pluginName]->upgrade();

								$app->db->sql('
									UPDATE `' . $app->db->prefix . 'versions` SET
										`version` = "' . $view->outdatedPlugins[$pluginName]['version'] . '"
									WHERE
										`plugin` = "' . $pluginName . '"
									LIMIT 1
									;');

								$pluginsUpgraded[] = $pluginName;

								unset($view->outdatedPlugins[$pluginName]);
							}
						}

						if ( $pluginsUpgraded )
						{
							header('Location: ?notice=upgraded&plugins=' . implode('|', $pluginsUpgraded));

							$app->end();
						}

						break;
					case 'remove':
						$pluginsRemoved = array();

						foreach ( $app->input->POST_valid['plugin'] as $pluginName => $v )
						{
							if ( isset($view->installedPlugins[$pluginName]) && !in_array(1, $view->installedPlugins[$pluginName]['required_by_status']) )
							{
								$app->db->sql('
									DELETE
									FROM `' . $app->db->prefix . 'versions`
									WHERE
										`plugin` = "' . $app->db->escape($pluginName) . '"
									LIMIT 1
									;');

								$app->pluginsLoaded[$pluginName]->remove();

								$pluginsRemoved[] = $pluginName;

								unset($view->installedPlugins[$pluginName]);
							}
						}

						if ( $pluginsRemoved )
						{
							header('Location: ?notice=removed&plugins=' . implode('|', $pluginsRemoved));

							$app->end();
						}

						break;
				}
			}
		}
	}
}

if ( isset($app->input->GET_raw['notice']) && isset($app->input->GET_raw['plugins']) )
{
	switch ( $app->input->GET_raw['notice'] )
	{
		case 'installed':
			$view->notice = $view->t('The following plugin(s) have been successfully installed:%1$s', '<br/><br/>' . str_replace('|', '<br/>', $app->input->GET_html_safe['plugins']));

			break;
		case 'upgraded':
			$view->notice = $view->t('The following plugin(s) have been successfully upgraded:%1$s', '<br/><br/>' . str_replace('|', '<br/>', $app->input->GET_html_safe['plugins']));

			break;
		case 'removed':
			$view->notice = $view->t('The following plugin(s) have been successfully removed:%1$s', '<br/><br/>' . str_replace('|', '<br/>', $app->input->GET_html_safe['plugins']));

			break;
	}
}

$view->authenticated = $authenticated;

$view->load('installer.html.php');

$app->end();