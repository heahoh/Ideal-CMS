<?php
/**
 * Ideal CMS (http://idealcms.ru/)
 *
 * @link      http://github.com/ideals/idealcms репозиторий исходного кода
 * @copyright Copyright (c) 2012-2014 Ideal CMS (http://idealcms.ru/)
 * @license   http://idealcms.ru/license.html LGPL v3
 */
namespace Ideal\Structure\Service\UpdateCms;

use Ideal\Core\Config;

/**
 * Обновление IdealCMS или одного модуля
 *
 */
class AjaxController extends \Ideal\Core\AjaxController
{
    /** @var string Сервер обновлений */
    protected $srv = 'http://idealcms.ru/update';

    /** @var Model  */
    protected $updateModel;

    public function __construct()
    {
        $config = Config::getInstance();
        $this->updateModel = new Model();

        $getFileScript = $this->srv . '/get.php';


        if (is_null($config->cms['tmpFolder']) || ($config->cms['tmpFolder'] == '')) {
            $this->updateModel->addMessage('В настройках не указана папка для хранения временных файлов', 'error');
            $this->updateModel->uExit(true);
        }

        // Папка для хранения загруженных файлов обновлений
        $uploadDir = DOCUMENT_ROOT . $config->cms['tmpFolder'] . '/update';
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                $this->updateModel->addMessage('Не удалось создать папку' . $uploadDir, 'error');
                $this->updateModel->uExit(true);
            }
        }

        // Папка для разархивации файлов новой CMS
        // Пример /www/example.com/tmp/setup/Update
        define('SETUP_DIR', $uploadDir . '/setup');
        if (!file_exists(SETUP_DIR)) {
            if (!mkdir(SETUP_DIR, 0755, true)) {
                $this->updateModel->addMessage('Не удалось создать папку' . SETUP_DIR, 'error');
                $this->updateModel->uExit(true);
            }
        }

        $this->updateModel->setUpdateFolders(
            array(
                'getFileScript' => $getFileScript,
                'uploadDir' => $uploadDir
            )
        );

        if (!isset($_POST['version']) || !isset($_POST['name'])) {
            $this->updateModel->addMessage('Непонятно, что обновлять. Не указаны version и name', 'error');
            $this->updateModel->uExit(true);
        } else {

            $this->updateModel->setUpdate($_POST['name'], $_POST['version']);
        }

        // Создаём сессию для хранения данных между ajax запросами
        session_start();
        if (isset($_SESSION['update'])) {
            if ($_SESSION['update']['name'] != $this->updateModel->updateName ||
                $_SESSION['update']['version'] != $this->updateModel->updateVersion) {
                unset($_SESSION['update']);
            }
        }
        if (!isset($_SESSION['update'])) {
            $_SESSION['update'] = array(
                'name' => $this->updateModel->updateName,
                'version' => $this->updateModel->updateVersion,
            );
        }
    }

    /**
     * Загрузка архива с обновлениями
     */
    public function ajaxDownloadAction()
    {
        // Скачиваем архив с обновлениями
        $_SESSION['update']['archive'] = $this->updateModel->downloadUpdate();
        $this->updateModel->addMessage('Загружен архив с обновлениями', 'success');
        exit();
    }

    // Распаковка архива с обновлением
    public function ajaxUnpackAction()
    {
        $archive = isset($_SESSION['update']['archive']) ? $_SESSION['update']['archive'] : null;
        if (!$archive) {
            $this->updateModel->addMessage('Неполучен путь к файлу архива', 'error');
            $this->updateModel->uExit(true);
        }
        $this->updateModel->unpackUpdate($archive);
        $this->updateModel->addMessage('Распакован архив с обновлениями', 'success');
        exit();
    }

    /**
     * Замена старого каталога на новый
     */
    public function ajaxSwapAction()
    {
        $_SESSION['oldFolder'] = $this->updateModel->swapUpdate();
        $this->updateModel->addMessage('Заменены файлы', 'success');
        exit();
    }

    /**
     * Получение скриптов, которые необходимо выполнить для перехода на новую версию
     */
    public function ajaxGetUpdateScriptAction()
    {
        // Запускаем выполнение скриптов и запросов
        $_SESSION['scripts'] = $this->updateModel->getUpdateScripts();
        $this->updateModel->addMessage('Получен список скриптов в количстве: ' . count($_SESSION['scripts']), 'success');
        $this->updateModel->uExit(false, array('count' =>count($_SESSION['scripts'])));
    }

    /**
     * Выполнение одного скрипта из списка полученных скриптов
     */
    public function ajaxRunScriptAction()
    {
        if (!isset($_SESSION['scripts'])) {
            exit();
        }
        // Получаем скрипт, выполняемый в текущем ajax запросе
        $script = array_shift($_SESSION['scripts']);
        // Если все скрипты были выполнены ранее, возвращаем false
        if (!$script) {
            exit();
        }
        // Запускаем выполнение скриптов и запросов
        $this->updateModel->runScript($script);
        $this->updateModel->addMessage('Выполнен скрипт: ' . $script, 'success');
        exit();
    }

    /**
     * Последний этап выполнения обновления
     */
    public function ajaxFinishAction()
    {
        // Модуль установился успешно, делаем запись в лог обновлений
        $this->updateModel->writeLog(
            'Installed ' . $this->updateModel->updateName . ' v. ' . $this->updateModel->updateVersion
        );

        // Получаем раздел со старой версией
        $oldFolder = isset($_SESSION['update']['oldFolder']) ? $_SESSION['update']['oldFolder'] : null;
        $oldFolderError = '';
        if (!$oldFolder) {
            $this->updateModel->addMessage('Не удалось удалить раздел со старой версией.', 'warring');
        }
        // Удаляем старую папку
        $this->updateModel->removeDirectory($oldFolder);

        $this->updateModel->addMessage('Обновление завершено успешно' . $oldFolderError, 'success');
        exit();
    }

    public function __destruct()
    {
        $result = $this->updateModel->getData();
        echo json_encode($result);
    }
}
