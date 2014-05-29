<?php defined('BX_DOL') or die('hack attempt');
/**
 * Copyright (c) BoonEx Pty Limited - http://www.boonex.com/
 * CC-BY License - http://creativecommons.org/licenses/by/3.0/
 *
 * @defgroup    DolphinCore Dolphin Core
 * @{
 */

bx_import('BxDolIO');

class BxDolInstallerUtils extends BxDolIO
{
    function __construct()
    {
        parent::__construct();
    }

    static public function isXsltEnabled()
    {
        if (((int)phpversion()) >= 5) {
            if (class_exists ('DOMDocument') && class_exists ('XsltProcessor'))
                return true;
        } else {
            if (function_exists('domxml_xslt_stylesheet_file'))
                return true;
            elseif (function_exists ('xslt_create'))
                return true;
        }
        return false;
    }

    static public function isAllowUrlInclude()
    {
        if (version_compare(phpversion(), "5.2", ">") == 1) {
            $sAllowUrlInclude = ini_get('allow_url_include');
            return !($sAllowUrlInclude == 0);
        };
        return false;
    }

    static public function isModuleInstalled($sUri)
    {
        bx_import('BxDolModuleQuery');
        return BxDolModuleQuery::getInstance()->isModule($sUri);
    }

    /**
     * Set module for delayed uninstall 
     */
    static public function setModulePendingUninstall($sUri, $bPendingUninstall = true)
    {
        bx_import('BxDolModuleQuery');
        return BxDolModuleQuery::getInstance()->setModulePendingUninstall($sUri, $bPendingUninstall);
    }

    /**
     * Check if module is pending for uninstall
     */
    static public function isModulePendingUninstall($sUri)
    {
        bx_import('BxDolModuleQuery');
        $a = BxDolModuleQuery::getInstance()->getModuleByUri($sUri);
        return $a['pending_uninstall'];
    }

    /**
     * Check all pending for uninstallation modules and uninstall them if no pending for deletion files are found
     */
    static public function checkModulesPendingUninstall()
    {
        bx_import('BxDolModuleQuery');
        $a = BxDolModuleQuery::getInstance()->getModules();
        foreach ($a as $aModule) {

            // after we make sure that all pending for deletion files are deleted
            if (!$aModule['pending_uninstall'] || BxDolStorage::isQueuedFilesForDeletion($aModule['name']))
                continue;

            // remove pending uninstall flag
            self::setModulePendingUninstall($aModule['uri'], false);

            // perform uninstallation
            bx_import('BxDolStudioInstallerUtils');
            $aResult = BxDolStudioInstallerUtils::getInstance()->perform($aModule['path'], 'uninstall');

            // send email nofitication
            $aTemplateKeys = array(
                'Module' => $aModule['title'],
                'Result' => _t('_Success'),
                'Message' => '',
            );

            if ($aResult['code'] > 0) {
                $aTemplateKeys['Result'] = _t('_Failed');
                $aTemplateKeys['Message'] = $aResult['message'];
            }

            bx_import('BxDolEmailTemplates');
            $aMessage = BxDolEmailTemplates::getInstance()->parseTemplate('t_DelayedModuleUninstall', $aTemplateKeys);
            sendMail (getParam('site_email'), $aMessage['Subject'], $aMessage['Body'], 0, array(), BX_EMAIL_SYSTEM);
        }
    }

    function addHtmlFields ($a)
    {
        $this->_addFields ('html', $a);
    }

    function removeHtmlFields ()
    {
        $this->_removeFields('html');
    }

    function addJsonFields ($a)
    {
        $this->_addFields ('json', $a);
    }

    function removeJsonFields ()
    {
        $this->_removeFields('json');
    }

    function addExceptionsFields ($a)
    {
        $this->_addFields ('exceptions', $a);
    }

    function removeExceptionsFields ()
    {
        $this->_removeFields('exceptions');
    }

    function updateSystemExceptions ()
    {
        $s = getParam('sys_exceptions_fields');
        if (!$s) {
            $a = array ();
        } else {
            $a = unserialize($s);
            unset($a['system']);
        }
        $a['system'] = array(
            'COOKIE.memberSession',
        );
        $s = serialize ($a);
        setParam ('sys_exceptions_fields', $s);
        // recreate cache
        $GLOBALS['MySQL']->cleanCache ('sys_exceptions_fields');
        $GLOBALS['MySQL']->fromCache ('sys_exceptions_fields', 'getOne', "SELECT `VALUE` FROM `sys_options` WHERE `Name` = 'sys_exceptions_fields' LIMIT 1");
    }

    function updateEmailTemplatesExceptions ()
    {
        $s = getParam('sys_exceptions_fields');
        if (!$s) {
            $a = array ();
        } else {
            $a = unserialize($s);
            unset($a['system_email_templates']);
        }
        $a['system_email_templates'] = $this->_getEmailTemplatesHtmlFields();
        $s = serialize ($a);
        setParam ('sys_exceptions_fields', $s);
        // recreate cache
        $oDb = BxDolDb::getInstance();
        $oDb->cleanCache ('sys_exceptions_fields');
        $oDb->fromCache ('sys_exceptions_fields', 'getOne', "SELECT `VALUE` FROM `sys_options` WHERE `Name` = 'sys_exceptions_fields' LIMIT 1");
    }

    function updateProfileFieldsHtml ()
    {
        $s = getParam('sys_html_fields');
        if (!$s) {
            $a = array ();
        } else {
            $a = unserialize($s);
            unset($a['system_profile_html']);
        }
        $a['system_profile_html'] = $this->_getSystemProfileHtmlFields();
        $s = serialize ($a);
        setParam ('sys_html_fields', $s);
        // recreate cache
        $oDb = BxDolDb::getInstance();
        $oDb->cleanCache ('sys_html_fields');
        $oDb->fromCache ('sys_html_fields', 'getOne', "SELECT `VALUE` FROM `sys_options` WHERE `Name` = 'sys_html_fields' LIMIT 1");
    }

    //--- Protected methods ---//

    function _getEmailTemplatesHtmlFields ()
    {
        $oDb = BxDolDb::getInstance();
        $aRet = array ();
        $a = $oDb->getAll ("SELECT `Name` FROM `sys_email_templates`");
        foreach ($a as $r) {
            $aRet[] = 'POST.' . $r['Name'] . '_Body';
            $aRet[] = 'REQUEST.' . $r['Name'] . '_Body';
            $aRet[] = 'POST.' . $r['Name'] . '_Subject';
            $aRet[] = 'REQUEST.' . $r['Name'] . '_Subject';
        }
        return $aRet;
    }

    function _getSystemProfileHtmlFields ()
    {
        $oDb = BxDolDb::getInstance();
        $aRet = array ();
        $a = $oDb->getAll ("SELECT `Name` FROM `sys_profile_fields` WHERE `Type` = 'html_area'");
        foreach ($a as $r) {
            $aRet[] = 'POST.' . $r['Name'];
            $aRet[] = 'REQUEST.' . $r['Name'];
        }
        return $aRet;
    }

    function _addFields ($sType, $a)
    {
        switch ($sType) {
        case 'html':
        case 'json':
        case 'exceptions':
            break;
        default:
            return array();
        }

        $s = getParam("sys_{$sType}_fields");
        if (!$s) {
            $a = array ($this->_aConfig['home_uri'] => $a);
        } else {
            $a = array_merge (unserialize($s), array ($this->_aConfig['home_uri'] => $a));
        }
        $s = serialize ($a);
        setParam ("sys_{$sType}_fields", $s);
    }

    function _removeFields ($sType)
    {
        switch ($sType) {
        case 'html':
        case 'json':
        case 'exceptions':
            break;
        default:
            return array();
        }

        $s = getParam("sys_{$sType}_fields");
        if (!$s) {
            return;
        }
        $a = unserialize($s);
        unset ($a[$this->_aConfig['home_uri']]);
        $s = serialize ($a);
        setParam ("sys_{$sType}_fields", $s);
    }

}

/** @} */
