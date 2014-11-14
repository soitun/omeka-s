<?php
namespace Omeka\Controller\Admin;

use Omeka\Form\UserForm;
use Omeka\Form\UserKeyForm;
use Omeka\Form\UserPasswordForm;
use Omeka\Model\Entity\ApiKey;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class UserController extends AbstractActionController
{
    public function indexAction()
    {
        return $this->redirect()->toRoute('admin/default', array(
            'controller' => 'user',
            'action' => 'browse',
        ));
    }

    public function addAction()
    {
        $view = new ViewModel;
        $form = new UserForm($this->getServiceLocator(), null, array('include_role' => true));

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                $formData = $form->getData();
                $response = $this->api()->create('users', $formData);
                if ($response->isError()) {
                    $form->setMessages($response->getErrors());
                } else {
                    $this->messenger()->addSuccess('User created.');
                    return $this->redirect()->toUrl($response->getContent()->url());
                }
            } else {
                $this->messenger()->addError('There was an error during validation');
            }
        }

        $view->setVariable('form', $form);
        return $view;
    }

    public function browseAction()
    {
        $view = new ViewModel;
        $page = $this->params()->fromQuery('page', 1);
        $query = $this->params()->fromQuery() + array('page' => $page);
        $response = $this->api()->search('users', $query);

        $this->paginator($response->getTotalResults(), $page);
        $view->setVariable('users', $response->getContent());
        return $view;
    }

    public function showAction()
    {
        $view = new ViewModel;
        $id = $this->params('id');
        $response = $this->api()->read('users', $id);

        $view->setVariable('user', $response->getContent());
        return $view;
    }

    public function showDetailsAction()
    {
        $view = new ViewModel;
        $view->setTerminal(true);
        $response = $this->api()->read(
            'users', array('id' => $this->params('id'))
        );
        if ($response->isError()) {
            $this->apiError($response);
            return;
        }
        $view->setVariable('user', $response->getContent());
        return $view;
    }

    public function editAction()
    {
        $view = new ViewModel;
        $form = new UserForm($this->getServiceLocator());
        $id = $this->params('id');

        $readResponse = $this->api()->read('users', $id);
        $user = $readResponse->getContent();
        $data = $user->jsonSerialize();
        $form->setData($data);

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                $formData = $form->getData();
                $response = $this->api()->update('users', $id, $formData);
                if ($response->isError()) {
                    $form->setMessages($response->getErrors());
                } else {
                    $this->messenger()->addSuccess('User updated.');
                    return $this->redirect()->refresh();
                }
            } else {
                $this->messenger()->addError('There was an error during validation');
            }
        }

        $view->setVariable('user', $user);
        $view->setVariable('form', $form);
        return $view;
    }

    public function changePasswordAction()
    {
        $view = new ViewModel;
        $form = new UserPasswordForm($this->getServiceLocator());
        $id = $this->params('id');

        $em = $this->getServiceLocator()->get('Omeka\EntityManager');
        $readResponse = $this->api()->read('users', $id);
        $userRepresentation = $readResponse->getContent();
        $user = $userRepresentation->getEntity();

        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                $values = $form->getData();
                $user->setPassword($values['password']);
                $em->flush();
                $this->messenger()->addSuccess('Password changed.');
                return $this->redirect()->toRoute(null, array('action' => 'edit'), array(), true);
            } else {
                $this->messenger()->addError('There was an error during validation');
            }
        }

        $view->setVariable('user', $userRepresentation);
        $view->setVariable('form', $form);
        return $view;
    }

    public function editKeysAction()
    {
        $view = new ViewModel;
        $form = new UserKeyForm($this->getServiceLocator());
        $id = $this->params('id');

        $em = $this->getServiceLocator()->get('Omeka\EntityManager');
        $readResponse = $this->api()->read('users', $id);
        $userRepresentation = $readResponse->getContent();
        $user = $userRepresentation->getEntity();
        $keys = $user->getKeys();

        if ($this->getRequest()->isPost()) {
            $postData = $this->params()->fromPost();
            $form->setData($postData);
            if ($form->isValid()) {
                $formData = $form->getData();
                $this->addKey($em, $user, $formData['new-key-label']);

                // Remove any keys marked for deletion
                if (!empty($postData['delete']) && is_array($postData['delete'])) {
                    foreach ($postData['delete'] as $deleteId) {
                        $keys->remove($deleteId);
                    }
                    $this->messenger()->addSuccess("Deleted key(s).");
                }
                $em->flush();
                return $this->redirect()->refresh();
            } else {
                $this->messenger()->addError('There was an error during validation');
            }
        }

        // Only expose key IDs and values to the view
        $viewKeys = array();
        foreach ($keys as $id => $key) {
            $viewKeys[$id] = $key->getLabel();
        }

        $view->setVariable('user', $userRepresentation);
        $view->setVariable('keys', $viewKeys);
        $view->setVariable('form', $form);
        return $view;
    }

    private function addKey($em, $user, $label)
    {
        if (empty($label)) {
            return;
        }

        $key = new ApiKey;
        $key->setId();
        $key->setLabel($label);
        $key->setOwner($user);
        $id = $key->getId();
        $credential = $key->setCredential();
        $em->persist($key);

        $this->messenger()->addSuccess('Key created.');
        $this->messenger()->addSuccess("ID: $id, Credential: $credential");
    }
}
