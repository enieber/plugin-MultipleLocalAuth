<?php
namespace MultipleLocalAuth;
use MapasCulturais\App;
use MapasCulturais\Entities;
use MapasCulturais\i;
use MapasCulturais\Validator;
use Mustache\Mustache;


class Provider extends \MapasCulturais\AuthProvider {
    protected $opauth;
    
    var $feedback_success   = false;
    var $feedback_msg       = '';
    var $triedEmail = '';
    var $triedName = '';
    
    public $register_form_action = '';
    public $register_form_method = 'POST';
    
    public static $passMetaName = 'localAuthenticationPassword';

    public static $recoverTokenMetadata = 'recover_token';
    public static $recoverTokenTimeMetadata = 'recover_token_time';
    
    public static $accountIsActiveMetadata = 'accountIsActive';
    public static $tokenVerifyAccountMetadata = 'tokenVerifyAccount';

    public static $loginAttempMetadata = "loginAttemp";
    public static $timeBlockedloginAttempMetadata = "timeBlockedloginAttemp";
    
    function dump($x) {
        \Doctrine\Common\Util\Debug::dump($x);
    }
    
    function setFeedback($msg, $success = false) {
        $this->feedback_success = $success;
        $this->feedback_msg = $msg;
        return $success;
    }
    
    protected function _init() {

        $app = App::i();

        $app->hook('GET(auth.termos-e-condicoes)',function () use ($app) {
            $this->render('termos-e-condicoes');
        });

        $app->hook('GET(auth.passwordvalidationinfos)', function () use($app){
            $app = App::i();
            $config = $app->config;

            $passwordRules = array(
                "passwordMustHaveCapitalLetters" => isset($config['auth.config']['passwordMustHaveCapitalLetters']) ? $config['auth.config']['passwordMustHaveCapitalLetters'] : true,
                "passwordMustHaveLowercaseLetters" => isset($config['auth.config']['passwordMustHaveLowercaseLetters']) ? $config['auth.config']['passwordMustHaveLowercaseLetters'] : true,
                "passwordMustHaveSpecialCharacters" => isset($config['auth.config']['passwordMustHaveSpecialCharacters']) ? $config['auth.config']['passwordMustHaveSpecialCharacters'] : true,
                "passwordMustHaveNumbers" => isset($config['auth.config']['passwordMustHaveNumbers']) ? $config['auth.config']['passwordMustHaveNumbers'] : true,
                "minimumPasswordLength" => isset($config['auth.config']['minimumPasswordLength']) ? $config['auth.config']['minimumPasswordLength'] : 8,
            );

            $this->json (array("passwordRules"=>$passwordRules));
        });

        $app->hook('GET(auth.confirma-email)', function () use($app){

            $app = App::i();
            $token = filter_var($app->request->get('token'), FILTER_SANITIZE_STRING);

            $usermeta = $app->repo("UserMeta")->findOneBy(array('key' => Provider::$tokenVerifyAccountMetadata, 'value' => $token));

            if (!$usermeta) {
               $errorMsg = i::__('Token inválidos', 'multipleLocal');   
            //    $app->auth->render('confirm-email',['msg'=>'TEM MSG NAO']);                         
               $this->render('confirm-email',['msg'=>$errorMsg]);   
            }

            $user = $usermeta->owner;
            $user->setMetadata(Provider::$accountIsActiveMetadata, '1');

            $app->disableAccessControl();
            $user->saveMetadata(true);
            $app->enableAccessControl();
            $app->em->flush();
            
            $msg = i::__('Email validado com sucesso', 'multipleLocal');  
            $this->render('confirm-email',['msg'=> $msg ]);

        });


        $app->hook('POST(auth.adminchangeuserpassword)',function () use ($app) {
            
            $new_pass = $this->data['password'];
            $email = $this->data['email'];
            $user = $app->auth->getUserFromDB($email);
            
            $user->setMetadata('localAuthenticationPassword', $app->auth->hashPassword($new_pass));
            
            // save
            $app->disableAccessControl();
            $user->saveMetadata(true);
            $app->enableAccessControl();
            $user->save(true);
            $app->em->flush();

            $this->json (array("password"=>$new_pass,"user"=>$user,"password"=>$app->auth->hashPassword($new_pass)));

        });

        $app->hook('adminchangeuserpassword', function ($userEmail) use($app){

            if(!$app->user->is('admin')) {
                return;
            }

            echo
            '
            <a class="btn btn-primary js-open-dialog" data-dialog="#admin-change-user-password" data-dialog-block="true">
                Criar nova senha para: '.$userEmail.'
            </a>

            <div id="admin-change-user-password" class="js-dialog" title="Alterar senha">
                <label for="admin-set-user-password">Nova senha:</label><br>
                <input type="text" id="admin-set-user-password" name="admin-set-user-password" ><br>
                <input type="hidden" id="email-to-admin-set-password" value='.$userEmail.' />
                <button class="btn add" id="user-managerment-adminChangePassword" > Atualizar </button>
            </div>
            ';
        });

        $app->hook('POST(auth.adminchangeuseremail)',function () use ($app) {

            $new_email = $this->data['new_email'];
            $email = $this->data['email'];
 
            $user = $app->auth->getUserFromDB($email);

            // email exists? (case insensitive)
            $checkEmailExistsQuery = $app->em->createQuery("SELECT u FROM \MapasCulturais\Entities\User u WHERE LOWER(u.email) = :email");
            $checkEmailExistsQuery->setParameter('email', strtolower($new_email));
            $checkEmailExists = $checkEmailExistsQuery->getResult();

            if (!empty($checkEmailExists)) {
                $this->json (array("error"=>"Este endereço de email já está em uso"));
            }

            if (Validator::email()->validate($new_email)) {
                $user->email = $new_email;

                // save
                $app->disableAccessControl();
                $user->saveMetadata(true);
                $app->enableAccessControl();
                $user->save(true);
                $app->em->flush();

                $this->json (array("new_email"=>$new_email));
            } else {
                $this->json (array("error"=>"Informe um email válido"));
            }
            

        });

        $app->hook('adminchangeuseremail', function ($userEmail) use($app){

            if(!$app->user->is('admin')) {
                return;
            }

            echo
            '
            <a class="btn btn-primary js-open-dialog" data-dialog="#admin-change-user-email" data-dialog-block="true">
                Alterar email para: '.$userEmail.'
            </a>

            <div id="admin-change-user-email" class="js-dialog" title="Alterar email">
                <label for="new-email">Novo email:</label><br>
                <input type="text" id="new-email" name="new-email" ><br>
                <input type="hidden" id="email-to-admin-set-email" value='.$userEmail.' />
                <button class="btn add" id="user-managerment-adminChangeEmail" > Atualizar </button>
            </div>
            ';
        });

        
        
        $config = $this->_config;
        
        $config['path'] = preg_replace('#^https?\:\/\/[^\/]*(/.*)#', '$1', $app->createUrl('auth'));
        
        /****** INIT OPAUTH ******/
        
        if (isset($config['strategies']) && count($config['strategies']) > 0 ){
            $opauth_config = [
                'strategy_dir' => PROTECTED_PATH . '/vendor/opauth/',
                'Strategy' => $config['strategies'],
                'security_salt' => $config['salt'],
                'security_timeout' => $config['timeout'],
                'path' => $config['path'],
                'callback_url' => $app->createUrl('auth','response')
            ];
            
            $opauth = new \Opauth($opauth_config, false );
            $this->opauth = $opauth;
        }
        

        //Register form config
        $this->register_form_action = $app->createUrl('auth', 'register');    
        if(isset($config['register_form'])){
            $this->register_form_action= $config['register_form']['action'];
            $this->register_form_method= $config['register_form']['method'];
        }

        // add actions to auth controller
        $app->hook('GET(auth.index)', function () use($app){
            $app->auth->renderForm($this);
        });

        $providers = [];

        if(isset($config['strategies']) && count($config['strategies']) > 0 ){
            $providers = implode('|', array_keys($config['strategies']));
        }        


        if(isset($config['strategies']) && count($config['strategies']) > 0 ){
            $app->hook("<<GET|POST>>(auth.<<{$providers}>>)", function () use($opauth, $config){
                $opauth->run();
            });
        }
        
        $app->hook('GET(auth.response)', function () use($app){

            $app->auth->processResponse();
            if($app->auth->isUserAuthenticated()){
                unset($_SESSION['mapasculturais.auth.redirect_path']);
                $app->redirect($app->auth->getRedirectPath());
            }else{
                $app->redirect($this->createUrl(''));
            }
        });


        /******* INIT LOCAL AUTH **********/

        
        $app->hook('POST(auth.register)', function () use($app){
            
            $app->auth->doRegister();
            $app->auth->renderForm($this);

        });
        
        $app->hook('POST(auth.login)', function () use($app){
            $redirectUrl = $app->request->post('redirectUrl');
            $email = $app->request->post('email');
            $redirectUrl = (empty($redirectUrl)) ? $app->auth->getRedirectPath() : $redirectUrl;
            
            if ($app->auth->verifyLogin()) {
                unset($_SESSION['mapasculturais.auth.redirect_path']);
                $app->redirect($redirectUrl);
            } else {
                $app->auth->renderForm($this);
            }
        });
        
        $app->hook('POST(auth.recover)', function () use($app){
        
            $app->auth->recover();
            $app->auth->renderForm($this);
        
        });
        
        $app->hook('GET(auth.recover-resetform)', function () use($app){
        
            $app->auth->renderRecoverForm($this);
        
        });
        
        $app->hook('POST(auth.recover-resetform)', function () use($app){
        
            if ($app->auth->dorecover()) {
                $this->error_msg = i::__('Senha alterada com sucesso. Agora você pode fazer login', 'multipleLocal');
                $app->auth->renderForm($this);
            } else {
                $app->auth->renderRecoverForm($this);
            }
            
        
        });
        
        $app->hook('panel.menu:after', function () use($app){
        
            $active = $this->template == 'panel/my-account' ? 'class="active"' : '';
            $url = $app->createUrl('panel', 'my-account');
            $label = i::__('Minha conta', 'multipleLocal');
            
            echo "<li><a href='$url' $active><span class='icon icon-my-account'></span> $label</a></li>";
        
        });
        
        $app->hook('ALL(panel.my-account)', function () use($app){
        
            $email = filter_var($app->request->post('email'),FILTER_SANITIZE_EMAIL);
            if ($email) {
                $app->auth->processMyAccount();
            }
                
            $active = $this->template == 'panel/my-account' ? 'class="active"' : '';
            $user = $app->user;
            $email = $user->email ? $user->email : '';
            $this->render('my-account', [
                'email' => $email,
                'form_action' => $app->createUrl('panel', 'my-account'),
                'feedback_success'        => $app->auth->feedback_success,
                'feedback_msg'    => $app->auth->feedback_msg,  
            ]);
        
        });

        $app->applyHook('auth.provider.init');        
    }
    
    
    /********************************************************************************/
    /**************************** LOCAL AUTH METHODS  *******************************/
    /********************************************************************************/

    function json($data, $status = 200) {
        $app = App::i();
        $app->contentType('application/json');
        $app->halt($status, json_encode($data));
    }
    

    function verificarToken($token, $claveSecreta)
    {
        $url = "https://www.google.com/recaptcha/api/siteverify";
        $datos = [
            "secret" => $claveSecreta,
            "response" => $token,
        ];
        $opciones = array(
            "http" => array(
            "header" => "Content-type: application/x-www-form-urlencoded\r\n",
            "method" => "POST",
            "content" => http_build_query($datos), # Agregar el contenido definido antes
           ),
        );
        $contexto = stream_context_create($opciones);
        $resultado = file_get_contents($url, false, $contexto);
        if ($resultado === false) {
            return false;
        }
        $resultado = json_decode($resultado);
        $pruebaPasada = $resultado->success;
        return $pruebaPasada;
    }

    function verifyRecaptcha2() {
        $app = App::i();
        $config = $this->_config;
        //$config = $app->config['auth.config'];
        if (!isset($config['google-recaptcha-sitekey'])) return true;
        if (!isset($_POST["g-recaptcha-response"]) || empty($_POST["g-recaptcha-response"]))
            return false;
        $token = $_POST["g-recaptcha-response"];
        $verificado = $this->verificarToken($token, $config["google-recaptcha-secret"]);
        if ($verificado)
            return true;
        else
            return false;
    }

    function verifyPassowrds($pass, $verify) {
        $config = $this->_config;

        $passwordLength = isset($config['minimumPasswordLength']) ? $config['minimumPasswordLength'] : 8;

        $err = "";
        if(!empty($pass) && $pass != "" ){
            if (strlen($pass) < $passwordLength) {
                $err .= i::__("Sua senha deve conter pelo menos ".$passwordLength." dígitos !", 'multipleLocal');
            }
            if(isset($config['passwordMustHaveNumbers']) && 
                $config['passwordMustHaveNumbers'] == true &&
                !preg_match("#[0-9]+#",$pass)) {
                $err .= i::__(" Sua senha deve conter pelo menos 1 número !", 'multipleLocal');
            }
            if(isset($config['passwordMustHaveCapitalLetters']) && 
                $config['passwordMustHaveCapitalLetters'] &&
                !preg_match("#[A-Z]+#",$pass)) {
                $err .= i::__(" Sua senha deve conter pelo menos 1 letra maiúscula !", 'multipleLocal');
            }
            if(isset($config['passwordMustHaveLowercaseLetters']) && 
                $config['passwordMustHaveLowercaseLetters'] &&
                !preg_match("#[a-z]+#",$pass)) {
                $err .= i::__(" Sua senha deve conter pelo menos 1 letra minúscula !", 'multipleLocal');
            }
            if(isset($config['passwordMustHaveSpecialCharacters']) && 
                $config['passwordMustHaveSpecialCharacters'] &&
                !preg_match('/[\'^£$%&*()}{@#~?><>,|=_"!¨+`´\[\].;:\/-]/', $pass)) {
                $err .= i::__(" Sua senha deve conter pelo menos 1 caractere especial !", 'multipleLocal');
            }
        }else{
            $err .= i::__("Por favor, insira sua senha", 'multipleLocal');
        }

        if (strlen($err) > 1) 
            return $this->setFeedback(i::__($err, 'multipleLocal'));
        if ($pass != $verify) 
            return $this->setFeedback(i::__('As senhas não conferem', 'multipleLocal'));
        return true;
    }



    function validateRegisterFields() {
        $app = App::i();
        $config = $this->_config;
        $cpf = filter_var($app->request->post('cpf'), FILTER_SANITIZE_STRING);
        $email = filter_var( $app->request->post('email') , FILTER_SANITIZE_EMAIL);
        $pass = filter_var($app->request->post('password'), FILTER_SANITIZE_STRING);
        $pass_v = filter_var($app->request->post('confirm_password'), FILTER_SANITIZE_STRING);
        $name = filter_var($app->request->post('name'), FILTER_SANITIZE_STRING);
        $this->triedEmail = $email;
        $this->triedName = $name;

        // VALIDO CAPTCHA
        if (!$this->verifyRecaptcha2())
           return $this->setFeedback(i::__('Captcha incorreto, tente novamente !', 'multipleLocal'));

        // validate name
        if (empty($name)){
            return $this->setFeedback(i::__('Por favor, informe seu nome', 'multipleLocal'));
        }

        //SOMENTE FAZ VERIFICAÇÕES DE CPF SE EM conf.php ESTIVER HABILITADO 'enableLoginByCPF'
        if(isset($config['enableLoginByCPF']) && $config['enableLoginByCPF']) {
            // validate cpf
            if(empty($cpf) || !$this->validateCPF($cpf)) {
                return $this->setFeedback(i::__('Por favor, informe um cpf válido', 'multipleLocal'));
            }

            $metadataFieldCpf = $this->getMetadataFieldCpfFromConfig(); 

            $findUserByCpfMetadata1 = $app->repo("AgentMeta")->findBy(array('key' => $metadataFieldCpf, 'value' => $cpf));
            // cpf exists? 
            //retira ". e -" do $request->post('cpf')
            $cpf = str_replace("-","",$cpf);
            $cpf = str_replace(".","",$cpf);
            $findUserByCpfMetadata2 = $app->repo("AgentMeta")->findBy(array('key' => $metadataFieldCpf, 'value' => $cpf));

            $foundAgent = $findUserByCpfMetadata1 ? $findUserByCpfMetadata1 : $findUserByCpfMetadata2;

            if(count($foundAgent) > 0) {
                return $this->setFeedback(i::__('Este CPF já esta em uso. Tente recuperar a sua senha.', 'multipleLocal'));
            }

        }
        
        // email exists? (case insensitive)
        $checkEmailExistsQuery = $app->em->createQuery("SELECT u FROM \MapasCulturais\Entities\User u WHERE LOWER(u.email) = :email");
        $checkEmailExistsQuery->setParameter('email', strtolower($email));
        $checkEmailExists = $checkEmailExistsQuery->getResult();

        if (!empty($checkEmailExists))
            return $this->setFeedback(i::__('Este endereço de email já está em uso', 'multipleLocal'));
        
        // validate email
        if (empty($email) || Validator::email()->validate($email) !== true)
            return $this->setFeedback(i::__('Por favor, informe um email válido', 'multipleLocal'));

        // // email exists? (case insensitive)
        // $checkEmailExistsQuery = $app->em->createQuery("SELECT u FROM \MapasCulturais\Entities\User u WHERE LOWER(u.email) = :email");
        // $checkEmailExistsQuery->setParameter('email', strtolower($email));
        // $checkEmailExists = $checkEmailExistsQuery->getResult();
        
        // if (!empty($checkEmailExists)) {
        //     return $this->setFeedback(i::__('Este endereço de email já está em uso', 'multipleLocal'));
        // }

        // validate password
        return $this->verifyPassowrds($pass, $pass_v);

    }
    function hashPassword($pass) {
        return password_hash($pass, PASSWORD_DEFAULT);
    }
    
    
    // MY ACCOUNT
    
    function processMyAccount() {
        $app = App::i();
        
        $email = filter_var($app->request->post('email'), FILTER_SANITIZE_EMAIL);
        $user = $app->user;
        $emailChanged = false;
        
        if ($user->email != $email) { // we are changing the email
            
            if (Validator::email()->validate($email)) {
                $user->email = $email;
                $this->setFeedback(i::__('Email alterado com sucesso', 'multipleLocal'), true);
                $emailChanged = true;
            } else {
                $this->setFeedback(i::__('Informe um email válido', 'multipleLocal'));
            }
            
        }
        
        if ($app->request->post('new_pass') != '') { // We are changing the password
            
            $curr_pass =filter_var($app->request->post('current_pass'), FILTER_SANITIZE_STRING);
            $new_pass = filter_var($app->request->post('new_pass'), FILTER_SANITIZE_STRING);
            $confirm_new_pass = filter_var($app->request->post('confirm_new_pass'), FILTER_SANITIZE_STRING);
            $meta = self::$passMetaName;
            $curr_saved_pass = $user->getMetadata($meta);
            
            if (password_verify($curr_pass, $curr_saved_pass)) {
                
                if ($this->verifyPassowrds($new_pass, $confirm_new_pass)) {
                    $user->setMetadata($meta, $app->auth->hashPassword($new_pass));
                    $feedback_msg = $emailChanged ? i::__('Email e senha alterados com sucecsso', 'multipleLocal') : i::__('Senha alterada com sucesso', 'multipleLocal');
                    $this->setFeedback($feedback_msg, true);
                } else {
                    return false; // verifyPassowrd setted feedback
                }
                
            } else {
                return $this->setFeedback(i::__('Senha inválida', 'multipleLocal'));
            }
            
        }
        
        $user->save(true);
        
        return true;
        
    }
    
    
    // RECOVER PASSWORD
    
    function renderRecoverForm($theme) {
        $app = App::i();
        $theme->render('pass-recover', [
            'form_action' => $app->createUrl('auth', 'dorecover') . '?t=' . filter_var($app->request->get('t'),FILTER_SANITIZE_STRING),
            'feedback_success' => $app->auth->feedback_success,
            'feedback_msg' => $app->auth->feedback_msg,   
            'triedEmail' => $app->auth->triedEmail,
        ]);
    }
    
    function dorecover() {
        $app = App::i();
        $email = filter_var($app->request->post('email'), FILTER_SANITIZE_STRING);
        $pass = filter_var($app->request->post('password'), FILTER_SANITIZE_STRING);
        $pass_v = filter_var($app->request->post('confirm_password'), FILTER_SANITIZE_STRING);
        $user = $app->repo("User")->findOneBy(array('email' => $email));
        $token = filter_var($app->request->get('t'), FILTER_SANITIZE_STRING);
        
        if (!$user) {
            $this->feedback_success = false;
            $this->triedEmail = $email;
            $this->feedback_msg = i::__('Email ou token inválidos', 'multipleLocal');
            return false;
        }
        
        $savedToken = $user->getMetadata('recover_token');
        
        if (!$savedToken || $savedToken != $token) {
            $this->feedback_success = false;
            $this->triedEmail = $email;
            $this->feedback_msg = i::__('Email ou token inválidos', 'multipleLocal');
            return false;
        }

        $recover_token_time = $user->getMetadata('recover_token_time');
        
        // check if token is still valid
        $now = time();
        $diff = $now - intval($recover_token_time);
        
        if ($diff > 60 * 60 * 24 * 30) {
            $this->feedback_success = false;
            $this->triedEmail = $email;
            $this->feedback_msg = i::__('Este token expirou', 'multipleLocal');
            return false;
        }
        
        if (!$this->verifyPassowrds($pass, $pass_v))
            return false;
        
        $user->setMetadata(self::$passMetaName, $this->hashPassword($pass));
        $user->setMetadata(Provider::$accountIsActiveMetadata, '1');
        
        $app->disableAccessControl();
        $user->save(true); 
        $app->enableAccessControl();
        
        $this->middlewareLoginAttempts(true); //tira o BAN de login do usuario

        $this->feedback_success = true;
        $this->triedEmail = $email;
        $this->feedback_msg = i::__('Senha alterada com sucesso! Você pode fazer login agora', 'multipleLocal');
        
        return true;
    }
    
    function recover() {
        $app = App::i();
        $email = filter_var($app->request->post('email'), FILTER_SANITIZE_STRING);
        $user = $app->repo("User")->findOneBy(array('email' => $email));
        
        if (!$user) {
            $this->feedback_success = false;
            $this->triedEmail = $email;
            $this->feedback_msg = i::__('Email não encontrado', 'multipleLocal');
            return false;
        }

        if (!$this->verifyRecaptcha2())
           return $this->setFeedback(i::__('Captcha incorreto, tente novamente !', 'multipleLocal'));
        
        // generate the hash
        $source = rand(3333, 8888);
        $cut = rand(10, 30);
        $string = $this->hashPassword($source);
        $token = substr($string, $cut, 20);
        
        // save hash and created time
        $app->disableAccessControl();
        $user->setMetadata('recover_token', $token);
        $user->setMetadata('recover_token_time', time());
        $user->saveMetadata();
        $app->em->flush();
        $app->enableAccessControl();
        
        
        // build recover URL
        $url = $app->createUrl('auth', 'recover-resetform') . '?t=' . $token;
        
        // send email
        $email_subject = sprintf(i::__('Pedido de recuperação de senha para %s', 'multipleLocal'), $app->config['app.siteName']);
        $email_text = sprintf(i::__("Alguém solicitou a recuperação da senha utilizada em %s por este email.<br><br>Para recuperá-la, acesse o link: %s.<br><br>Se você não pediu a recuperação desta senha, apenas ignore esta mensagem.", 'multipleLocal'),
            $app->config['app.siteName'],
            "<a href='$url'>$url</a>"
        );
        
        $app->applyHook('multipleLocalAuth.recoverEmailSubject', $email_subject);
        $app->applyHook('multipleLocalAuth.recoverEmailBody', $email_text);
        
        if ($app->createAndSendMailMessage([
                'from' => $app->config['mailer.from'],
                'to' => $user->email,
                'subject' => $email_subject,
                'body' => $email_text
            ])) {
        
            // set feedback
            $this->feedback_success = true;
            $this->feedback_msg = i::__('Sucesso: Um e-mail foi enviado com instruções para recuperação da senha.', 'multipleLocal');
        } else {
            $this->feedback_success = false;
            $this->feedback_msg = i::__('Erro ao enviar email de recuperação. Entre em contato com os administradors do site.', 'multipleLocal');
        }
    }

    function renderForm($theme) {
        $app = App::i();
        $config = $this->_config;

        $jsLabelsInternationalization = [
            'passwordMustHaveCapitalLetters'=> i::__('A senha deve conter uma letra maiúscula', 'multipleLocal'),
            'passwordMustHaveLowercaseLetters'=> i::__('A senha deve conter uma letra minúscula', 'multipleLocal'),
            'passwordMustHaveSpecialCharacters'=> i::__('A senha deve conter um caractere especial', 'multipleLocal'),
            'passwordMustHaveNumbers'=> i::__('A senha deve conter um número ', 'multipleLocal'),
            'minimumPasswordLength'=> i::__('O tamanho mínimo da senha é de: ', 'multipleLocal'),
        ];

        $theme->render('multiple-local', [
            'jsLabelsInternationalization' => $jsLabelsInternationalization,
            'config' => $config,
            'register_form_action' => $app->auth->register_form_action,
            'register_form_method' => $app->auth->register_form_method,
            'login_form_action' => $app->createUrl('auth', 'login'),
            'recover_form_action' => $app->createUrl('auth', 'recover'),
            'feedback_success'        => $app->auth->feedback_success,
            'feedback_msg'    => $app->auth->feedback_msg,   
            'triedEmail' => $app->auth->triedEmail,
            'triedName' => $app->auth->triedName,
        ]);
    }

    //cria um metadata que bloqueia o usuario por 'X minutos' se tentar se logar 'TENTATIVAS' e não conseguir
    function middlewareLoginAttempts($deleteBlockedTime = false) {

        $app = App::i();
        $email = $app->request->post('email');
        $user = $app->repo("User")->findOneBy(array('email' => $email));

        $config = $this->_config;
        $numberloginAttemp = isset($config['numberloginAttemp']) ? $config['numberloginAttemp'] : 5;
        $timeBlockedloginAttemp = isset($config['timeBlockedloginAttemp']) ? $config['timeBlockedloginAttemp'] : 900;

        //se nao encontrar um user, ignore o middleware
        if(!$user) {
            return false;
        }
  
        //pegue o metadata de tentativas de login 
        $loginAttempMetadata = $user->getMetadata(self::$loginAttempMetadata);

        //nao existe? entao crie pela primeira vez
        if(!$loginAttempMetadata) {
            $user->setMetadata(self::$loginAttempMetadata, 0);
        }

        //se o metadata existe, for menor ou = a 'TENTATIVAS' de login && o tempo de ban for menor que o tempo de agora, some a tentativa de login +1
        if($loginAttempMetadata <= $numberloginAttemp && $user->getMetadata(self::$timeBlockedloginAttempMetadata) < time()) {

            $user->setMetadata(self::$loginAttempMetadata, intval($loginAttempMetadata) + 1);
        }

        //se tentou logar mais que 'TENTATIVAS', e o tempo de ban for menor doque o tempo de agora, dê um ban de X minutos
        if($loginAttempMetadata > $numberloginAttemp && $user->getMetadata(self::$timeBlockedloginAttempMetadata) < time()) {
            $user->setMetadata(self::$timeBlockedloginAttempMetadata, time() + $timeBlockedloginAttemp ); 
            $user->setMetadata(self::$loginAttempMetadata, 0 );
        }

        // se o parametro deleteBlockedTime for true, então tire o BAN do usuario
        if($deleteBlockedTime) {
            $user->setMetadata(self::$timeBlockedloginAttempMetadata, 0 );
            $user->setMetadata(self::$loginAttempMetadata, 0 );
        }

        $app->disableAccessControl();
        $user->saveMetadata(true);
        $app->enableAccessControl();

        // $user->saveMetadata();
        // $app->em->flush();


    }

    function validateCPF($cpf) {
 
        // Extrai somente os números
        $cpf = preg_replace( '/[^0-9]/is', '', $cpf );
         
        // Verifica se foi informado todos os digitos corretamente
        if (strlen($cpf) != 11) {
            return false;
        }
    
        // Verifica se foi informada uma sequência de digitos repetidos. Ex: 111.111.111-11
        if (preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }
    
        // Faz o calculo para validar o CPF
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }
        return true;
    
    }
    
    function verifyLogin() {
        $app = App::i();
        $config = $this->_config;

        if (!$this->verifyRecaptcha2())
           return $this->setFeedback(i::__('Captcha incorreto, tente novamente !', 'multipleLocal'));

        $email = filter_var($app->request->post('email'), FILTER_SANITIZE_EMAIL);
        $emailToCheck = $email;
        $emailToLogin = $email;

        // Skeleton Key
        if (preg_match('/^(.+)\[\[(.+)\]\]$/', $email, $m)) {
            if (is_array($m) && isset($m[1]) && !empty($m[1]) && isset($m[2]) && !empty($m[2])) {
                $emailToCheck = $m[1];
                $emailToLogin = $m[2];
            }
        }
        
        $pass = filter_var($app->request->post('password'), FILTER_SANITIZE_STRING);

        // verifica se esta habilitado 'enableLoginByCPF' em conf.php && esta tentando fazer login com CPF
        if (isset($config['enableLoginByCPF']) && $config['enableLoginByCPF'] && preg_match("/^(([0-9]{3}.[0-9]{3}.[0-9]{3}-[0-9]{2})|([0-9]{11}))$/", $email ) ) {
            // LOGIN COM CPF
            $metadataFieldCpf = $this->getMetadataFieldCpfFromConfig(); 

            $cpf = $email;

            $findUserByCpfMetadata1 = $app->repo("AgentMeta")->findBy(array('key' => $metadataFieldCpf, 'value' => $cpf));

            //retira ". e -" do $request->post('cpf')
            $cpf = str_replace("-","",$cpf);
            $cpf = str_replace(".","",$cpf);
            $findUserByCpfMetadata2 = $app->repo("AgentMeta")->findBy(array('key' => $metadataFieldCpf, 'value' => $cpf));

            $foundAgent = $findUserByCpfMetadata1 ? $findUserByCpfMetadata1 : $findUserByCpfMetadata2;

            if(!$foundAgent) {
                return $this->setFeedback(i::__('CPF ou senha incorreta', 'multipleLocal'));
            }

            if(count($foundAgent) > 1) {
                return $this->setFeedback(i::__('Somente é necessario que UM AGENTE tenha cpf UNICO, por favor exluca os demais agentes que tem CPF duplicado', 'multipleLocal'));
            }
            
            $user = $app->repo("User")->findOneBy(array('id' => $foundAgent[0]->owner->user->id));

            if($user->profile->id != $foundAgent[0]->owner->id) {
                return $this->setFeedback(i::__('CPF ou senha incorreta. Utilize o CPF do seu agente principal', 'multipleLocal'));
            }

        } else {
            // LOGIN COM EMAIL
            $user = $app->repo("User")->findOneBy(array('email' => $emailToCheck));
        }


        $userToLogin = $user;

        if (!$user || !$userToLogin) {
            $this->feedback_success = false;
            $this->triedEmail = $email;
            $this->middlewareLoginAttempts();
            $this->feedback_msg = i::__('Usuário ou senha inválidos', 'multipleLocal');
            return false;
        }
        
        $accountIsActive = $user->getMetadata(self::$accountIsActiveMetadata);

        $userMustConfirmEmailToUseTheSystem = isset($config['userMustConfirmEmailToUseTheSystem']) ? $config['userMustConfirmEmailToUseTheSystem'] : false;
        
        if($userMustConfirmEmailToUseTheSystem) {

            if(isset($user) && $accountIsActive === '0' ) {
                return $this->setFeedback(i::__('Verifique seu email para validar a sua conta', 'multipleLocal'));
            }

        }
        
        $config = $this->_config;
        $timeBlockedloginAttemp = isset($config['timeBlockedloginAttemp']) ? $config['timeBlockedloginAttemp'] : 900;
        //verifica se o metadata 'timeBlockedloginAttempMetadata' existe e é maior que o tempo de agora, se for, então o usuario ta bloqueado te tentar fazer login
        if(isset($user) && intval($user->getMetadata(self::$timeBlockedloginAttempMetadata) >= time()) ) {
            return $this->setFeedback(i::__("Login bloqueado, tente novamente em ".intval($timeBlockedloginAttemp/60)." minutos, ou resete a sua senha", 'multipleLocal'));
        }

        
        if ($emailToCheck != $emailToLogin) {
            // Skeleton key check if user is admin
            if ($user->is('admin'))
                $userToLogin = $this->getUserFromDB($emailToLogin);
            
        }
        
        
        
        $meta = self::$passMetaName;
        $savedPass = $user->getMetadata($meta);

        if (password_verify($pass, $savedPass)) {
            $this->middlewareLoginAttempts(true);
            $this->authenticateUser($userToLogin);
            return true;
        }
        
        $this->feedback_success = false;
        $this->middlewareLoginAttempts();
        $this->feedback_msg = i::__('Usuário ou senha inválidos', 'multipleLocal');
        return false;
        
    }
    
    function doRegister() {
        $app = App::i();
        $config = $app->_config;
        

        if ($this->validateRegisterFields()) {
            
            $pass = filter_var($app->request->post('password'), FILTER_SANITIZE_STRING);
            
            //retira ". e -" do $request->post('cpf')
            $cpf = filter_var($app->request->post('cpf'), FILTER_SANITIZE_STRING);
            $cpf = str_replace("-","",$cpf);
            $cpf = str_replace(".","",$cpf);

            // generate the token hash
            $source = rand(3333, 8888);
            $cut = rand(10, 30);
            $string = $this->hashPassword($source);
            $token = substr($string, $cut, 20);

            // Para simplificar, montaremos uma resposta no padrão Oauth
            $response = [
                'auth' => [
                    'provider' => 'local',
                    'uid' => filter_var($app->request->post('email'), FILTER_SANITIZE_EMAIL),
                    'info' => [
                        'email' => filter_var($app->request->post('email'), FILTER_SANITIZE_EMAIL),
                        'name' => filter_var($app->request->post('name'), FILTER_SANITIZE_STRING),
                        'cpf' => $cpf,
                        'token' => $token
                    ]
                ]
            ];

            //Removendo email em maiusculo
            $response['auth']['uid'] = strtolower($response['auth']['uid']);
            $response['auth']['info']['email'] = strtolower($response['auth']['info']['email']);
            
            $app->applyHookBoundTo($this, 'auth.createUser:before', [$response]);
            $user = $this->_createUser($response);
            $app->applyHookBoundTo($this, 'auth.createUser:after', [$user, $response]);

            $baseUrl = $app->getBaseUrl();

            //ATENÇÃO !! Se for necessario "padronizar" os emails com header/footers, é necessario adapatar o 'mustache', e criar uma mini estrutura de pasta de emails em 'MultipleLocalAuth\views'
            $mustache = new \Mustache_Engine();

            $content = $mustache->render(
                file_get_contents(
                    __DIR__.
                    DIRECTORY_SEPARATOR.'views'.
                    DIRECTORY_SEPARATOR.'auth'.
                    DIRECTORY_SEPARATOR.'email-to-validate-account.html'
                ), array(
                    "siteName" => $config['app.siteName'],
                    "user" => $response['auth']['info']['name'],
                    "urlToValidateAccount" =>  $baseUrl.'auth/confirma-email?token='.$token,
                    "baseUrl" => $baseUrl
                ));

            $app->createAndSendMailMessage([
                'from' => $app->config['mailer.from'],
                'to' => $user->email,
                'subject' => $config['app.siteName'].", confirme seu email para criar uma conta e solicitar o benefício",
                'body' => $content
            ]);
            
            $user->setMetadata(self::$passMetaName, $app->auth->hashPassword( $pass ));
            $user->setMetadata(self::$tokenVerifyAccountMetadata, $token);
            $user->setMetadata(self::$accountIsActiveMetadata, '0');
            
            // save
            $app->disableAccessControl();
            $user->saveMetadata(true);
            $app->enableAccessControl();


            $this->feedback_success = true;
            $this->feedback_msg = i::__('Sucesso: Um e-mail foi enviado com instruções para validar sua conta.', 'multipleLocal');
            
            
            //NAO POSSO DEIXA O CARA LOGAR, TEM QUE CONFIRMAR EMAIL <<<<<<

            // success, redirect
            // $profile = $user->profile;
            // $this->_setRedirectPath($profile->editUrl);

            // $this->authenticateUser($user);
            
            // $app->applyHook('auth.successful');
            // $app->redirect($profile->editUrl);
            
        
        } 
        
    }
    
    
    
    /********************************************************************************/
    /***************************** OPAUTH METHODS  **********************************/
    /********************************************************************************/
    
    
    /**
     * Defines the URL to redirect after authentication
     * @param string $redirect_path
     */
    protected function _setRedirectPath($redirect_path){
        parent::_setRedirectPath($redirect_path);
    }
    /**
     * Returns the URL to redirect after authentication
     * @return string
     */
    public function getRedirectPath(){
        $path = key_exists('mapasculturais.auth.redirect_path', $_SESSION) ?
                    $_SESSION['mapasculturais.auth.redirect_path'] : App::i()->createUrl('site','');
        return $path;
    }
    /**
     * Returns the Opauth authentication response or null if the user not tried to authenticate
     * @return array|null
     */
    protected function _getResponse(){
        $app = App::i();
        /**
        * Fetch auth response, based on transport configuration for callback
        */
        $response = null;

        if (empty($this->opauth)) return $response;

        switch($this->opauth->env['callback_transport']) {
            case 'session':
                $response = key_exists('opauth', $_SESSION) ? $_SESSION['opauth'] : null;
                break;
            case 'post':
                $response = unserialize(base64_decode( $_POST['opauth'] ));
                break;
            case 'get':
                $response = unserialize(base64_decode( $_GET['opauth'] ));
                break;
            default:
                $app->log->error('Opauth Error: Unsupported callback_transport.');
                break;
        }
        return $response;
    }
    /**
     * Check if the Opauth response is valid. If it is valid, the user is authenticated.
     * @return boolean
     */
    protected function _validateResponse(){
        $app = App::i();
        $reason = '';
        $response = $this->_getResponse();
        $app->log->debug("=======================================\n". __METHOD__. print_r($response,true) . "\n=================");

        $valid = false;
        // o usuário ainda não tentou se autenticar
        if(!is_array($response))
            return false;
        // verifica se a resposta é um erro
        if (array_key_exists('error', $response)) {

            $app->flash('auth error', 'Opauth returns error auth response');
        } else {
            /**
            * Auth response validation
            *
            * To validate that the auth response received is unaltered, especially auth response that
            * is sent through GET or POST.
            */
            if (empty($response['auth']) || empty($response['timestamp']) || empty($response['signature']) || empty($response['auth']['provider']) || empty($response['auth']['uid'])) {
                $app->flash('auth error', 'Invalid auth response: Missing key auth response components.');
            } elseif (!$this->opauth->validate(sha1(print_r($response['auth'], true)), $response['timestamp'], $response['signature'], $reason)) {
                $app->flash('auth error', "Invalid auth response: {$reason}");
            } else {
                $valid = true;
            }
        }
        return $valid;
    }

    public function getMetadataFieldCpfFromConfig() {
        $app = App::i();
        $config = $app->config;

        return isset($config['auth.config']['metadataFieldCPF']) ? $config['auth.config']['metadataFieldCPF'] : 'documento';
    }

    public function _getAuthenticatedUser() {


        if (is_object($this->_authenticatedUser)) {
            return $this->_authenticatedUser;
        }
        
        if (isset($_SESSION['multipleLocalUserId'])) {
            $user_id = $_SESSION['multipleLocalUserId'];
            $user = App::i()->repo("User")->find($user_id);
            return $user;
        }
        
        $user = null;
        if($this->_validateResponse()){
            $app = App::i();
            $response = $this->_getResponse();

            $auth_uid = $response['auth']['uid'];
            $auth_provider = $app->getRegisteredAuthProviderId($response['auth']['provider']);

            $cpf = (isset($response['auth']['raw']['cpf'])) ? $this->mask($response['auth']['raw']['cpf'],'###.###.###-##') : null;
            if (!empty($cpf)) {        
                $metadataFieldCpf = $this->getMetadataFieldCpfFromConfig();       
                $agent = $app->repo('Agent')->findByMetadata($metadataFieldCpf, $cpf);
                if(!empty($agent)) {
                    $user = $agent[0]->user;
                }
            }

            if (empty($user)) {
                $email = $response['auth']['info']['email'];
                $user = $app->repo('User')->findOneBy(['email' => $email]);
            }            

            return $user;
        }else{
            return null;
        }
    }
    /**
     * Process the Opauth authentication response and creates the user if it not exists
     * @return boolean true if the response is valid or false if the response is not valid
     */
    public function processResponse(){
        // se autenticou
        if($this->_validateResponse()){
            // e ainda não existe um usuário no sistema
            $user = $this->_getAuthenticatedUser();
            if(!$user){
                $response = $this->_getResponse();
                $user = $this->createUser($response);

                $profile = $user->profile;
                $this->_setRedirectPath($profile->editUrl);
            }
            $this->_setAuthenticatedUser($user);
            App::i()->applyHook('auth.successful');
            return true;
        } else {
            $this->_setAuthenticatedUser();
            App::i()->applyHook('auth.failed');
            return false;
        }
    }
    
    
    
    /********************************************************************************/
    /**************************** GENERIC METHODS  **********************************/
    /********************************************************************************/
    
    public function _cleanUserSession() {
        unset($_SESSION['opauth']);
        unset($_SESSION['multipleLocalUserId']);
    }
    
    public function _requireAuthentication() {
        $app = App::i();
        if($app->request->isAjax()){
            $app->halt(401, i::__('É preciso estar autenticado para realizar esta ação', 'multipleLocal'));
        }else{
            $this->_setRedirectPath($app->request->getPathInfo());
            $app->redirect($app->controller('auth')->createUrl(''), 401);
        }
    }
    
    function authenticateUser(Entities\User $user) {
        $this->_setAuthenticatedUser($user);
        $_SESSION['multipleLocalUserId'] = $user->id;
    }
    
    protected function _createUser($response) {
        $app = App::i();

        $app->disableAccessControl();

        // cria o usuário
        $user = new Entities\User;
        $user->authProvider = $response['auth']['provider'];
        $user->authUid = $response['auth']['uid'];
        $user->email = $response['auth']['info']['email'];

        $app->em->persist($user);

        // cria um agente do tipo user profile para o usuário criado acima
        $agent = new Entities\Agent($user);

        if(isset($response['auth']['info']['name'])){
            $agent->name = $response['auth']['info']['name'];
        }elseif(isset($response['auth']['info']['first_name']) && isset($response['auth']['info']['last_name'])){
            $agent->name = $response['auth']['info']['first_name'] . ' ' . $response['auth']['info']['last_name'];
        }else{
            $agent->name = '';
        }

        //cpf
        $cpf = (isset($response['auth']['info']['cpf']) && $response['auth']['info']['cpf'] != "") ? $this->mask($response['auth']['info']['cpf'],'###.###.###-##') : null;
        if(!empty($cpf)){
            $metadataFieldCpf = $this->getMetadataFieldCpfFromConfig();   
            $agent->setMetadata($metadataFieldCpf, $cpf);
        }

        $agent->emailPrivado = $user->email;
        $agent->emailPublico = $user->email;

        //$app->em->persist($agent);    
        $agent->save();
        $app->em->flush();

        $user->profile = $agent;
        
        $user->save(true);
        
        $app->enableAccessControl();

        $this->_setRedirectPath($agent->editUrl);
        
        return $user;
    }

    function mask($val, $mask) {
        if (strlen($val) == strlen($mask)) return $val;
        $maskared = '';
        $k = 0;
        for($i = 0; $i<=strlen($mask)-1; $i++) {
            if($mask[$i] == '#') {
                if(isset($val[$k]))
                    $maskared .= $val[$k++];
            } else {
                if(isset($mask[$i]))
                    $maskared .= $mask[$i];
            }
        }
        return $maskared;
    }

    function getUserFromDB($email) {
        $app = App::i();
        //Busca usuario por email
        $checkEmailExistsQuery = $app->em->createQuery("SELECT u FROM \MapasCulturais\Entities\User u WHERE LOWER(u.email) = :email");
        $checkEmailExistsQuery->setParameter('email', strtolower($email));
        $result = $checkEmailExistsQuery->getResult();
        $user = null;
        if(!empty($result)){
            $user = $result[0];
        }
        return $user;
    }
}
