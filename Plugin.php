<?php

namespace SpamDetector;

use Mustache;
use MapasCulturais\i;
use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\Space;
use MapasCulturais\Entities\Project;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Notification;

class Plugin extends \MapasCulturais\Plugin
{
    public function __construct($config = [])
    {
        $default_terms = [
            'minecraft',
            'venda',
            'compra',
            'compre',
            'vendo',
            'vende',
            'nazismo',
            'fascismo',
            'hitler',
            'premium',
            'grátis',
            'gratuito',
            'download',
            'baixar',
            'vadia',
            'puta',
            'canalha'
        ];

        $terms_block = [
            'citotec',
            'cytotec',
            'apk',
            'install',
            'installer',
            'instale',
            'instalar',
            'instalador',
        ];

        $config += [
            'terms' => env('SPAM_DETECTOR_TERMS', $default_terms),
            'entities' => env('SPAM_DETECTOR_ENTITIES', ['Agent', 'Opportunity', 'Project', 'Space', 'Event']),
            'fields' => env('SPAM_DETECTOR_FIELDS', ['name', 'shortDescription', 'longDescription', 'nomeSocial', 'nomeCompleto', 'comunidadesTradicionalOutros']),
            'termsBlock' => env('SPAM_DETECTOR_TERMS_BLOCK', $terms_block)
        ];

        parent::__construct($config);
    }

    public function _init()
    {
        $app = App::i();
        $plugin = $this;

        $hooks = implode('|', $plugin->config['entities']);

        // Verifica se existem termos maliciosos e dispara o e-mail e a notificação
        $app->hook("entity(<<{$hooks}>>).save:after", function () use ($plugin, $app) {
            /** @var Entity $this */
            $users = $plugin->getAdminUsers($this);
            $terms = array_merge($plugin->config['termsBlock'], $plugin->config['terms']);

            $spam_terms = $plugin->getSpamTerms($this, $terms);

            if ($spam_terms) {
                foreach ($users as $user) {
                    $plugin->createNotification($user->profile, $this, $spam_terms);
                }

                $dict_entity = $plugin->dictEntity($this);
                $message = i::__("{$dict_entity} {$this->name} foi enviado para moderação");
                $notification = new Notification;
                $notification->user = $this->ownerUser;
                $notification->message = $message;
                $notification->save(true);
            }
        });

        // Garante que o agente fique em rascunho caso exista termos detectados
        $app->hook("entity(<<{$hooks}>>).save:before", function () use ($plugin, $app) {
            /** @var Entity $this */
            if($plugin->getSpamTerms($this, $plugin->config['termsBlock'])) {
                $this->setStatus(0);
                $this->spamBlock = true;
            }
        });

        // remove a permissão de publicar caso encontre termos que estão na lista de termos elegível a bloqueio
        $app->hook("entity(<<{$hooks}>>).canUser(publish)", function ($user, &$result) use($plugin, $app) {
            /** @var Entity $this */
            if($plugin->getSpamTerms($this, $plugin->config['termsBlock']) && !$user->is('admin')) {
                $result = false;
            }
        });
    }
    
    public function register() {}
    
    public function createNotification($recipient, $entity, $spam_detections)
    {
        $app = App::i();
        $app->disableAccessControl();
        
        $is_save = !$entity->spamBlock;
        $message = $this->getNotificationMessage($entity, $is_save);
        $notification = new Notification;
        $notification->user = $recipient->user;
        $notification->message = $message;
        $notification->save(true);
        
        $filename = $app->view->resolveFilename("views/emails", "email-spam.html");
        $template = file_get_contents($filename);
        
        $field_translations = [
            "name" => i::__("Nome"),
            "shortDescription" => i::__("Descrição Curta"),
            "longDescription" => i::__("Descrição Longa"),
        ];
        
        $detected_details = [];
        foreach ($spam_detections as $detection) {
            $translated_field = isset($field_translations[$detection['field']]) ? $field_translations[$detection['field']] : $detection['field'];
            $detected_details[] = "Campo: $translated_field, Termos: " . implode(', ', $detection['terms']) . '<br>';
        }
        
        $params = [
            "siteName" => $app->siteName,
            "nome" => $entity->name,
            "id" => $entity->id,
            "url" => $entity->singleUrl,
            "baseUrl" => $app->getBaseUrl(),
            "detectedDetails" => implode("\n", $detected_details),
        ];
        
        $mustache = new \Mustache_Engine();
        $content = $mustache->render($template, $params);

        if ($email = $this->getAdminEmail($recipient)) {
            $app->createAndSendMailMessage([
                'from' => $app->config['mailer.from'],
                'to' => $email,
                'subject' => i::__('Notificação de spam'),
                'body' => $content,
            ]);
        }

        $app->enableAccessControl();
    }   

    /**
     *  Retorna o texto relacionado a entidade
     * @param Entity $entity 
     * @return string 
     */
    public function dictEntity(Entity $entity, $type = "preposição"): string
    {
        $class = $entity->getClassName();

        switch ($type) {
            case 'preposição':
                $prefixes = (object) ["f" => "na", "m" => "no"];
                break;
            default:
                $prefixes = (object) ["f" => "a", "m" => "o"];
                break;
        }

        $entities = [
            Agent::class => "{$prefixes->m} Agente",
            Opportunity::class => "{$prefixes->f} Oportunidade",
            Project::class => "{$prefixes->m} Projeto",
            Space::class => "{$prefixes->m} Espaço",
            Event::class => "{$prefixes->m} Evento",
        ];

        return $entities[$class];
    }

    /**
     * @param string $text
     * @return string
     */
    public function formatText($text)
    {
        $text = trim($text);
        $text = strip_tags($text);
        $text = str_replace(["\n", "\t"], "", $text);
        $text = mb_strtolower($text);
        $text = preg_replace("/[^a-z0-9]/", "", $text);

        return $text;
    }

    /**
     * @param object $entity Objeto da entidade que deve ter a propriedade `subsiteId`. A presença desta propriedade determina o tipo de papéis a serem recuperados.
     * 
     * @return array Um array contendo os IDs dos usuários que têm um papel administrativo. O array pode estar vazio se nenhum papel administrativo for encontrado.
    */
    public function getAdminUserIds($entity): array {
        $app = App::i();

        $roles = $entity->subsiteId ? $app->repo('Role')->findBy(['subsiteId' => $entity->subsiteId]) : $app->repo('Role')->findAll();
        $role_type = $entity->subsiteId ? 'admin' : 'saasSuperAdmin';
        
        $user_ids = [];
        if ($roles) {
            foreach ($roles as $role) {
                if ($role->name == $role_type) {
                    $user_ids[] = $role->userId;
                }
            }
        }

        return $user_ids;
    }

    /**
     * @param object $entity Objeto da entidade a ser validada. A entidade deve ter propriedades que correspondem aos campos configurados.
     * 
     * @return array Retorna um array contendo os campos onde termos de spam foram encontrados.
    */
    public function getSpamTerms($entity, $terms): array {
        $fields = $this->config['fields'];
        $spam_detector = [];
        $found_terms = [];
        
        foreach ($fields as $field) {
            if ($value = $entity->$field) {
                $lowercase_value = $this->formatText($value);
                
                foreach ($terms as $term) {
                    $lowercase_term = $this->formatText($term);

                    if (strpos($lowercase_value, $lowercase_term) !== false && !in_array($lowercase_term, $found_terms)) {
                        $found_terms[] = $term;
                    }
                }
                
                if ($found_terms) {
                    $spam_detector[] = [
                        'terms' => $found_terms,
                        'field' => $field,
                    ];
                }
            }
        }

        return $spam_detector;
    }

    /**
     * @param object $entity Objeto da entidade que contém as propriedades `name` e `singleUrl`. A propriedade `name` é usada para identificar a entidade na mensagem, e `singleUrl` é o link para a verificação.
     * @param bool $is_save Indica o status de salvamento da entidade.
     * 
     * @return string Retorna uma mensagem formatada de notificação baseada no status de salvamento.
    */
    public function getNotificationMessage($entity, $is_save): string {
        $message_save = i::__("Possível spam detectado - <strong><i>{$entity->name}</i></strong><br><br> <a href='{$entity->singleUrl}'>Clique aqui</a> para verificar. Mais detalhes foram enviados para o seu e-mail");
        $message_insert = $message_insert = i::__("Possível spam detectado - <strong><i>{$entity->name}</i></strong><br><br> Apenas um administrador pode publicar este conteúdo. Mais detalhes foram enviados para o seu e-mail");

        $message = $is_save ? $message_save : $message_insert;

        return $message;
    }
    
    /**
     * @param object $agent Objeto que representa o agente. O objeto deve ter as propriedades `emailPrivado`, `emailPublico`, e `user` (que deve ter a propriedade `email`).
     * 
     * @return string O endereço de e-mail do agente.
    */
    public function getAdminEmail($agent): string {
        if($agent->emailPrivado) {
            $email = $agent->emailPrivado;
        } else if($agent->emailPublico) {
            $email = $agent->emailPublico;
        } else {
            $email = $agent->user->email;
        }

        return $email;
    }
}
