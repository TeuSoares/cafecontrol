<?php

namespace Source\App;

use Source\Core\Controller;
use Source\Models\Auth;
use Source\Models\CafeApp\AppCreditCard;
use Source\Models\CafeApp\AppOrder;
use Source\Models\CafeApp\AppPlan;
use Source\Models\CafeApp\AppSubscription;

/**
 * Class Pay
 * @package Source\App
 */
class Pay extends Controller
{
    /**
     * Pay constructor.
     */
    public function __construct()
    {
        parent::__construct(null);
    }

    /**
     * @param array $data
     * @throws \Exception
     */
    public function create(array $data): void
    {
        $user = Auth::user();
        $plan = (new AppPlan())->findById($data["plan"]);

        if (request_limit("paycreate", 3, 60 * 5)) {
            $json["message"] = $this->message->warning(
                "Desculpe {$user->first_name}, mas por segurança aguarde pelo menos 5 minutos para tentar outro cartão."
            )->render();
            echo json_encode($json);
            return;
        }

        $checkSubscribe = (new AppSubscription())->find(
            "user_id = :user AND status != :status",
            "user={$user->id}&status=canceled"
        )->fetch();

        if ($checkSubscribe) {
            $json["message"] = $this->message->warning(
                "Você já tem uma assinatura ativa {$user->first_name}. Não é necessário assinar o {$plan->name} mais de uma vez."
            )->render();
            echo json_encode($json);
            return;
        }

        /*
         * VALIDE A ENTRADA DOS DADOS ANTES DE FAZER ISSO:
         * Exemplo: IF TELEFONE FORMATO - IF CPF FORMATO E EXISTÊNCIA
         */
        $user->document = preg_replace("/[^0-9]/", "", $data["document"]);
        $user->phone = preg_replace("/[^0-9]/", "", $data["mobile_phone"]);
        $user->save();

        $creditCard = new AppCreditCard();
        $card = $creditCard->creditCard(
            $user,
            $data["card_number"],
            $data["card_holder_name"],
            $data["card_expiration_date"],
            $data["card_cvv"],
            $data["address_zip"],
            $data["address_state"],
            $data["address_city"],
            "{$data["address_number"]}, {$data["address_street"]}",
            $data["address_line_2"]
        );

        if (!$card) {
            $json["message"] = $this->message->warning(
                "Ooops! não foi possível validar o cartão. Favor verifique os dados para tentar assinar novamente."
            )->render();
            echo json_encode($json);
            return;
        }

        $transaction = $card->transaction($plan->id, $plan->name, $plan->price);
        if (!$transaction) {
            $json["message"] = $creditCard->message()->before("Ooops! ")->after(
                ". Você pode tentar novamente com um novo cartão."
            )->render();
            echo json_encode($json);
            return;
        }

        $subscription = (new AppSubscription())->subscribe($user, $plan, $card);
        (new AppOrder())->byCreditCard($user, $card, $subscription, $transaction);

        $this->message->success(
            "Bem-vindo(a) ao {$plan->plan} {$user->first_name}. Sua assinatura está ativa e você já pode controlar. Confira os detalhes..."
        )->flash();
        $json["redirect"] = url("/app/assinatura");
        echo json_encode($json);
    }

    /**
     * @param array $data
     */
    public function update(array $data): void
    {
        $user = Auth::user();
        $subscribe = (new AppSubscription())->find(
            "user_id = :user AND status != :status",
            "user={$user->id}&status=canceled"
        )->fetch();

        if (!$subscribe) {
            $json["message"] = $this->message->error("Ooops! Você não tem uma assinatura ativa")->render();
            echo json_encode($json);
            return;
        }

        if (request_limit("payupdate", 3, 60 * 5)) {
            $json["message"] = $this->message->warning(
                "Desculpe {$user->first_name}, mas por segurança aguarde pelo menos 5 minutos para tentar outro cartão."
            )->render();
            echo json_encode($json);
            return;
        }

        /*
         * VALIDE A ENTRADA DOS DADOS ANTES DE FAZER ISSO:
         * Exemplo: IF TELEFONE FORMATO - IF CPF FORMATO E EXISTÊNCIA
         */
        $user->document = preg_replace("/[^0-9]/", "", $data["document"]);
        $user->phone = preg_replace("/[^0-9]/", "", $data["mobile_phone"]);
        $user->save();

        $creditCard = new AppCreditCard();
        $card = $creditCard->creditCard(
            $user,
            $data["card_number"],
            $data["card_holder_name"],
            $data["card_expiration_date"],
            $data["card_cvv"],
            $data["address_zip"],
            $data["address_state"],
            $data["address_city"],
            "{$data["address_number"]}, {$data["address_street"]}",
            $data["address_line_2"]
        );

        if (!$card) {
            $json["message"] = $this->message->warning(
                "Não foi possível atualizar seu meio de pagamento. Favor verifique os dados do cartão"
            )->before("Ooops! ")->render();
            echo json_encode($json);
            return;
        }

        $subscribe->card_id = $card->id;
        $subscribe->save();

        if ($subscribe->status == "past_due") {
            $transaction = $card->transaction(
                $subscribe->plan()->id,
                $subscribe->plan()->name,
                $subscribe->plan()->price
            );
            if ($transaction) {
                $subscribe->status = "active";
                $subscribe->next_due = date("Y-m-d", strtotime($subscribe->next_due . "+{$subscribe->plan()->period}"));
                $subscribe->last_charge = date("Y-m-d");
                $subscribe->save();

                $this->message->after(" e sua assinatura já está regularizada.");
                (new AppOrder())->byCreditCard($user, $card, $subscribe, $transaction);
            }
        }

        $this->message->success("Seu meio de pagamento foi atualizado com sucesso")->flash();
        $json["redirect"] = url("/app/assinatura");
        echo json_encode($json);
    }
}