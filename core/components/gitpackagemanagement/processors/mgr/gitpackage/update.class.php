<?php
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/model/gitpackagemanagement/gitpackageconfig.class.php';
/**
 * Update a config file in database
 *
 * @package gitpackagemanagement
 * @subpackage processors
 */

class GitPackageManagementUpdatePackageProcessor extends modObjectUpdateProcessor {
    public $classKey = 'GitPackage';
    public $languageTopics = array('gitpackagemanagement:default');
    public $objectType = 'gitpackagemanagement.package';
    /** @var GitPackage $object */
    public $object;
    /** @var GitPackageConfig $oldConfig */
    private $oldConfig;
    /** @var GitPackageConfig $oldConfig */
    private $newConfig;
    private $category;
    private $recreateDatabase = 0;

    public function beforeSet() {

        $packagePath = $this->modx->getOption('gitpackagemanagement.packages_dir',null,null);
        if($packagePath == null){
            return $this->modx->lexicon('gitpackagemanagement.package_err_ns_packages_dir');
        }

        $packagePath .= $this->object->dir_name;

        $configFile = $packagePath . $this->modx->gitpackagemanagement->configPath;
        if(!file_exists($configFile)){
            return $this->modx->lexicon('gitpackagemanagement.package_err_url_config_nf');
        }

        $config = file_get_contents($configFile);

        $config = $this->modx->fromJSON($config);

        $this->newConfig = new GitPackageConfig($this->modx, $packagePath);
        if($this->newConfig->parseConfig($config) == false) {
            return $this->modx->lexicon('gitpackagemanagement.package_err_url_config_nf');
        }

        $this->oldConfig = new GitPackageConfig($this->modx, $packagePath);
        $this->oldConfig->parseConfig($this->modx->fromJSON($this->object->config));

        $this->recreateDatabase = $this->getProperty('recreateDatabase', 0);

        $update = $this->update();
        if($update !== true){
            return $update;
        }

        $this->setProperty('config', $this->modx->toJSON($config));

        return parent::beforeSet();
    }

    private function update() {
        $vc = version_compare($this->oldConfig->getVersion(), $this->newConfig->getVersion());
        if($vc != -1){
            return $this->modx->lexicon('gitpackagemanagement.package_err_nvil');
        }

        if($this->oldConfig->getName() != $this->newConfig->getName()){
            return $this->modx->lexicon('gitpackagemanagement.package_err_ccn');
        }

        if($this->oldConfig->getLowCaseName() != $this->newConfig->getLowCaseName()){
            return $this->modx->lexicon('gitpackagemanagement.package_err_ccln');
        }

        $this->object->set('description', $this->newConfig->getDescription());
        $this->object->set('version', $this->newConfig->getVersion());

        $this->updateDatabase();
        $this->updateActionsAndMenus();
        $this->updateExtensionPackage();
        $this->updateSystemSettings();
        $this->updateElements();

        return true;
    }

    private function updateActionsAndMenus() {
        $actions = $this->modx->getCollection('modAction', array('namespace' => $this->newConfig->getLowCaseName()));
        /** @var modAction $action */
        foreach($actions as $action){
            $action->remove();
        }

        $actions = array();
        $menus = array();

        /**
         * Create actions if any
         */
        if(count($this->newConfig->getActions()) > 0){
            /** @var $act GitPackageConfigAction */
            foreach($this->newConfig->getActions() as $act){
                $actions[$act->getId()] = $this->modx->newObject('modAction');
                $actions[$act->getId()]->fromArray(array(
                                                        'namespace' => $this->newConfig->getLowCaseName(),
                                                        'controller' => $act->getController(),
                                                        'haslayout' => $act->getHasLayout(),
                                                        'lang_topics' => $act->getLangTopics(),
                                                        'assets' => $act->getAssets(),
                                                   ),'',true,true);
                $actions[$act->getId()]->save();
            }
        }

        /**
         * Crete menus if any
         */
        if(count($this->newConfig->getMenus()) > 0){
            /** @var $men GitPackageConfigMenu */
            foreach($this->newConfig->getMenus() as $i => $men){
                $menus[$i] = $this->modx->newObject('modMenu');
                $menus[$i]->fromArray(array(
                                           'text' => $men->getText(),
                                           'parent' => $men->getParent(),
                                           'description' => $men->getDescription(),
                                           'icon' => $men->getIcon(),
                                           'menuindex' => $men->getMenuIndex(),
                                           'params' => $men->getParams(),
                                           'handler' => $men->getHandler(),
                                      ),'',true,true);
                $menus[$i]->addOne($actions[$men->getAction()]);
                $menus[$i]->save();
            }
        }

    }

    private function updateExtensionPackage() {
        $extPackage = $this->oldConfig->getExtensionPackage();
        if($extPackage !== false){
            $this->modx->removeExtensionPackage($this->newConfig->getLowCaseName());
        }

        $extPackage = $this->newConfig->getExtensionPackage();
        if($extPackage !== false){
            $packagePath = $this->modx->getOption('gitpackagemanagement.packages_dir',null,null);
            $modelPath = $packagePath . $this->object->dir_name . "/core/components/" . $this->newConfig->getLowCaseName() . "/" . 'model/';
            $modelPath = str_replace('\\', '/', $modelPath);
            if($extPackage === true){
                $this->modx->addExtensionPackage($this->newConfig->getLowCaseName(),$modelPath);
            }else{
                $this->modx->addExtensionPackage($this->newConfig->getLowCaseName(),$modelPath, array(
                      'serviceName' => $extPackage['serviceName'],
                      'serviceClass' => $extPackage['serviceClass']
                 ));
            }
        }
    }

    private function updateSystemSettings() {
        $oldSettings = $this->oldConfig->getSettings();
        $notUsedSettings = array_keys($this->oldConfig->getSettings());
        $notUsedSettings = array_flip($notUsedSettings);

        /** @var GitPackageConfigSetting $setting */
        foreach($this->newConfig->getSettings() as $key => $setting){
            /** @var modSystemSetting $systemSetting */
            $systemSetting = $this->modx->getObject('modSystemSetting', array('key' => $this->newConfig->getLowCaseName() . '.' . $key));
            if (!$systemSetting){
                $systemSetting = $this->modx->newObject('modSystemSetting');
                $systemSetting->set('key', $this->newConfig->getLowCaseName() . '.' . $key);
                $systemSetting->set('value',$setting->getValue());
                $systemSetting->set('namespace', $this->newConfig->getLowCaseName());
                $systemSetting->set('area',$setting->getArea());
                $systemSetting->set('xtype', $setting->getType());
            }else{
                if(!isset($oldSettings[$key]) || $oldSettings[$key]->getValue() != $setting->getValue()){
                    $systemSetting->set('value',$setting->getValue());
                }
                $systemSetting->set('area',$setting->getArea());
                $systemSetting->set('xtype', $setting->getType());
            }
            $systemSetting->save();

            if(isset($notUsedSettings[$key])){
                unset($notUsedSettings[$key]);
            }
        }

        foreach($notUsedSettings as $key => $value){
            /** @var modSystemSetting $setting */
            $setting = $this->modx->getObject('modSystemSetting', array('key' => $this->newConfig->getLowCaseName() . '.' . $key));
            if ($setting) {
                $setting->remove();
            };
        }

        return true;
    }

    private function updateElements() {
        /** @var modCategory category */
        $this->category = $this->modx->getObject('modCategory', array('category' => $this->newConfig->getName()));
        if($this->category){
            $this->category = $this->category->id;
        }else{
            $this->category = 0;
        }

        $this->updateElement('Chunk');
        $this->updateElement('Snippet');
        $this->updateElement('Template');
        $this->updateElement('Plugin');
        $this->updateTV();
    }

    private function updateElement($type) {
        $configType = strtolower($type). 's';
        $notUsedElements = array_keys($this->oldConfig->getElements($configType));
        $notUsedElements = array_flip($notUsedElements);

        foreach($this->newConfig->getElements($configType) as $name => $element){
            if($type == 'Template'){
                $elementObject = $this->modx->getObject('mod'.$type, array('templatename' => $name));
            }else{
                $elementObject = $this->modx->getObject('mod'.$type, array('name' => $name));
            }
            if (!$elementObject){
                $elementObject = $this->modx->newObject('mod'.$type);
                if($type == 'Template'){
                    $elementObject->set('templatename', $element->getName());
                }else{
                    $elementObject->set('name', $element->getName());
                }
                $elementObject->set('static', 1);
                $elementObject->set('static_file', '[[++' . $this->newConfig->getLowCaseName() . '.core_path]]elements/' . $configType . '/' . $element->getFile());
                $elementObject->set('category', $this->category);
            }else{
                $elementObject->set('static', 1);
                $elementObject->set('static_file', '[[++' . $this->newConfig->getLowCaseName() . '.core_path]]elements/' . $configType . '/' . $element->getFile());
                $elementObject->set('category', $this->category);
            }

            if($type == 'Plugin'){
                $oldEvents = $elementObject->getMany('PluginEvents');
                /** @var modPluginEvent $oldEvent */
                foreach($oldEvents as $oldEvent){
                    $oldEvent->remove();
                }
                $events = array();

                foreach($element->getEvents() as $event){
                    $events[$event]= $this->modx->newObject('modPluginEvent');
                    $events[$event]->fromArray(array(
                                                    'event' => $event,
                                                    'priority' => 0,
                                                    'propertyset' => 0,
                                               ),'',true,true);
                }

                $elementObject->addMany($events, 'PluginEvents');
            }

            $elementObject->save();

            if(isset($notUsedElements[$name])){
                unset($notUsedElements[$name]);
            }
        }

        foreach($notUsedElements as $name => $value){
            if($type == 'Template'){
                $element = $this->modx->getObject('mod'.$type, array('templatename' => $name));
            }else{
                $element = $this->modx->getObject('mod'.$type, array('name' => $name));
            }

            if ($element) {
                $element->remove();
            }
        }

        return true;
    }

    private function updateTV() {
        $notUsedElements = array_keys($this->oldConfig->getElements('tvs'));
        $notUsedElements = array_flip($notUsedElements);

        /** @var GitPackageConfigElementTV $tv */
        foreach($this->newConfig->getElements('tvs') as $name => $tv){
            /** @var modTemplateVar $tvObject */
            $tvObject = $this->modx->getObject('modTemplateVar', array('name' => $name));

            if (!$tvObject){
                $tvObject = $this->modx->newObject('modTemplateVar');
                $tvObject->set('name', $tv->getName());
                $tvObject->set('caption', $tv->getCaption());
                $tvObject->set('description', $tv->getDescription());
                $tvObject->set('type', $tv->getInputType());
                $tvObject->set('category', $this->category);
            }else{
                $tvObject->set('caption', $tv->getCaption());
                $tvObject->set('description', $tv->getDescription());
                $tvObject->set('type', $tv->getInputType());
                $tvObject->set('category', $this->category);
            }

            $oldTemplates = $tvObject->getMany('TemplateVarTemplates');

            /** @var modTemplateVarTemplate $oldTemplate */
            foreach($oldTemplates as $oldTemplate){
                $oldTemplate->remove();
            }

            $tvObject->save();

            $templates = $this->modx->getCollection('modTemplate', array('templatename:IN' => $tv->getTemplates()));
            foreach($templates as $template){
                $templateTVObject = $this->modx->newObject('modTemplateVarTemplate');
                $templateTVObject->set('tmplvarid', $tv->id);
                $templateTVObject->set('templateid', $template->id);
                $templateTVObject->save();
            }

            if(isset($notUsedElements[$name])){
                unset($notUsedElements[$name]);
            }
        }

        foreach($notUsedElements as $name => $value){
            /** @var modTemplateVar $tv */
            $tv = $this->modx->getObject('modTemplateVar', array('name' => $name));

            if ($tv) {
                $tv->remove();
            }
        }

        return true;
    }

    private function updateDatabase() {
        $modelPath = $this->modx->getOption($this->newConfig->getLowCaseName().'.core_path',null,$this->modx->getOption('core_path').'components/'.$this->newConfig->getLowCaseName().'/').'model/';
        $this->modx->addPackage($this->newConfig->getLowCaseName(), $modelPath, $this->newConfig->getDatabase()->getPrefix());
        $manager = $this->modx->getManager();

        if($this->recreateDatabase){
            if($this->oldConfig->getDatabase() != null){
                foreach($this->oldConfig->getDatabase()->getTables() as $table){
                    $manager->removeObjectContainer($table);
                }
            }

            if($this->newConfig->getDatabase() != null){
                foreach($this->newConfig->getDatabase()->getTables() as $table){
                    $manager->createObjectContainer($table);
                }
            }
        }else{
            if($this->oldConfig->getDatabase() != null){
                $notUsedTables = $this->oldConfig->getDatabase()->getTables();
            }else{
                $notUsedTables = array();
            }
            $notUsedTables = array_flip($notUsedTables);

            if($this->newConfig->getDatabase() != null){
                foreach($this->newConfig->getDatabase()->getTables() as $table){
                    $manager->createObjectContainer($table);

                    if(isset($notUsedTables[$table])){
                        unset($notUsedTables[$table]);
                    }
                }
            }

            foreach($notUsedTables as $table){
                $manager->removeObjectContainer($table);
            }
        }
    }
}
return 'GitPackageManagementUpdatePackageProcessor';