<?php

namespace Plugin\TwoFactorAuthCustomerSms42\Controller;

use Plugin\TwoFactorAuthCustomer42\Controller\TwoFactorAuthCustomerController;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthSmsTypeCustomer;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthPhoneNumberTypeCustomer;
use Plugin\TwoFactorAuthCustomer42\Service\CustomerTwoFactorAuthService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;


class TwoFactorAuthCustomerSmsController extends TwoFactorAuthCustomerController
{

    /**
     * SMS認証 送信先入力画面.
     * @Route("/two_factor_auth/tfa/sms/send_onetime", name="plg_customer_2fa_sms_send_onetime", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomerSms42/Resource/template/default/tfa/sms/send.twig")
     */
    public function inputPhoneNumber(Request $request) 
    {
        if ($this->isTwoFactorAuthed()) {
            return $this->redirectToRoute($this->getCallbackRoute());
        }

        $error = null;
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        $builder = $this->formFactory->createBuilder(TwoFactorAuthPhoneNumberTypeCustomer::class);
        $form = null;
        // 入力フォーム生成
        $form = $builder->getForm();

        // デバイス認証済み電話番号が設定済みの場合は優先して利用
        $phoneNumber = ($Customer->getDeviceAuthedPhoneNumber() != null ? $Customer->getDeviceAuthedPhoneNumber() : $Customer->getTwoFactorAuthedPhoneNumber());

        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isSubmitted()) {
                if ($Customer->isTwoFactorAuth() && $phoneNumber) {
                    // 初回認証済み
                    // 前回送信した電話番号へワンタイムコードを送信
                    $this->sendToken($Customer, $phoneNumber);
                    $response = new RedirectResponse($this->generateUrl('plg_customer_2fa_sms_input_onetime'));
                    // 送信電話番号をセッションへ一時格納
                    $this->session->set(
                        CustomerTwoFactorAuthService::SESSION_AUTHED_PHONE_NUMBER, 
                        $phoneNumber
                    );
                    return $response;
                } else {
                    // 初回認証時
                    if ($form->isValid()) {
                        $phoneNumber = $form->get('phone_number')->getData();
                        // 入力された電話番号へワンタイムコードを送信
                        $this->sendToken($Customer, $phoneNumber);
                        $response = new RedirectResponse($this->generateUrl('plg_customer_2fa_sms_input_onetime'));
                        // 送信電話番号をセッションへ一時格納
                        $this->session->set(
                            CustomerTwoFactorAuthService::SESSION_AUTHED_PHONE_NUMBER, 
                            $phoneNumber
                        );
                        return $response;
                    } else {
                        $error = trans('front.2fa.sms.send.failure_message');
                    }
                }
            }
        }

        return [
            'form' => $form->createView(),
            'Customer' => $Customer,
            'phoneNumber' => $phoneNumber,
            'error' => $error,
        ];
    }

    /**
     * SMS認証 ワンタイムトークン入力画面.
     * @Route("/two_factor_auth/tfa/sms/input_onetime", name="plg_customer_2fa_sms_input_onetime", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomerSms42/Resource/template/default/tfa/sms/input.twig")
     */
    public function inputToken(Request $request) 
    {
        if ($this->isTwoFactorAuthed()) {
            return $this->redirectToRoute($this->getCallbackRoute());
        }

        $error = null;
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        $builder = $this->formFactory->createBuilder(TwoFactorAuthSmsTypeCustomer::class);
        $form = null;
        $auth_key = null;
        // 入力フォーム生成
        $form = $builder->getForm();
        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            $token = $form->get('one_time_token')->getData();
            if ($form->isSubmitted() && $form->isValid()) {
                if (!$this->checkToken($Customer, $token)) {
                    // ワンタイムトークン不一致 or 有効期限切れ
                    $error = trans('front.2fa.onetime.invalid_message__reinput');
                } else {
                    // 送信電話番号をセッションより取得
                    $phoneNumber = $this->session->get(CustomerTwoFactorAuthService::SESSION_AUTHED_PHONE_NUMBER);
                    // ワンタイムトークン一致
                    // 二段階認証完了
                    $Customer->setTwoFactorAuth(true);
                    $Customer->setTwoFactorAuthedPhoneNumber($phoneNumber);
                    $this->entityManager->persist($Customer);
                    $this->entityManager->flush();

                    $response = new RedirectResponse($this->generateUrl($this->getCallbackRoute()));
                    $response->headers->setCookie(
                        $this->customerTwoFactorAuthService->createAuthedCookie(
                            $Customer, 
                            $this->getCallbackRoute()
                        )
                    );
                    return $response;
                }
            } else {
                $error = trans('front.2fa.onetime.invalid_message__reinput');
            }
        }

        return [
            'form' => $form->createView(),
            'Customer' => $Customer,
            'error' => $error,
        ];
    }

    /**
     * ワンタイムトークンを送信.
     * 
     * @param \Eccube\Entity\Customer $Customer
     * @param string $phoneNumber 
     * 
     */
    private function sendToken($Customer, $phoneNumber) 
    {
        // ワンタイムトークン生成・保存
        $token = $Customer->createTwoFactorAuthOneTimeToken();
        $this->entityManager->persist($Customer);
        $this->entityManager->flush();

        // ワンタイムトークン送信メッセージをレンダリング
        $twig = 'TwoFactorAuthCustomer42/Resource/template/default/sms/onetime_message.twig';
        $body = $this->twig->render($twig , [
            'Customer' => $Customer,
            'token' => $token,
        ]);

        // SMS送信
        return $this->customerTwoFactorAuthService->sendBySms($Customer, $phoneNumber, $body);
    }

    /**
     * ワンタイムトークンチェック.
     * 
     * @return boolean
     */
    private function checkToken($Customer, $token)
    {
        $now = new \DateTime();
        if ($Customer->getTwoFactorAuthOneTimeToken() !== $token 
            || $Customer->getTwoFactorAuthOneTimeTokenExpire() < $now) {
            return false;
        }
        return true;
    }

}
