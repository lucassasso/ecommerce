<?php 

namespace Hcode\Model;

use \Hcode\DB\Sql;
use \Hcode\Model;
use \Hcode\Mailer;
use \Hcode\Model\User;

class Cart extends Model {

    const SESSION = "Cart";
    const SESSION_ERROR = "CartError";

    public static function getFromSession(){
        //Obtém o carrinho pela sessão do usuário
        $cart = new Cart();
        //Se há itens adicionados ao carrinho, então será recuperado o carrinho da sessão do usuário
        if(isset($_SESSION[Cart::SESSION]) && (int)$_SESSION[Cart::SESSION]['idcart'] > 0){
        //O usuário recuperou o carrinho
            $cart->get((int)$_SESSION[Cart::SESSION]['idcart']);

        } else {
        //Criação de um novo carrinho
        /*
        Obtém pelo ID da sessão o carrinho, verifica se o ID é maior que zero e, então verifica se o usuário está logado, adicionando os produtos ao carrinho, salvando-os na sessão
        */
            $cart->getFromSessionID();

            if(!(int)$cart->getidcart() > 0){

                $data = [
                    'dessessionid'=>session_id()
                ];

                if(User::checkLogin(false)){

                    $user = User::getFromSession();

                    $data['iduser'] = $user->getiduser();

                }

                $cart->setData($data);

                $cart->save();

                $cart->setToSession();

            }

        }

        return $cart;

    }

    public function setToSession(){
        //Configura os valores no carrinho da sessão
        $_SESSION[Cart::SESSION] = $this->getValues();

    }

    

    public function getFromSessionID(){
    //Obtém o carrinho presente no banco de dados pelo id da sessão
        $sql = new Sql();

        $results = $sql->select("SELECT * FROM tb_carts WHERE dessessionid = :dessessionid", [
            ':dessessionid'=>session_id()
        ]);
        
        if(count($results) > 0){
            
            $this->setData($results[0]);
            
        }

    }

    public function get(int $idcart){
        //Obtém o carrinho presente no banco de dados pelo id do carrinho
        $sql = new Sql();

        $results = $sql->select("SELECT * FROM tb_carts WHERE idcart = :idcart", [
            ':idcart'=>$idcart
        ]);

        if(count($results) > 0){
            
            $this->setData($results[0]);

        }


    }

	
    public function save(){
    //retorna o id do carrinho, a sessão, usuário, CEP, valor do frete e prazo de entrega
        
        $sql = new Sql();

        $results = $sql->select("CALL sp_carts_save(:idcart, :dessessionid, :iduser, :deszipcode, :vlfreight, :nrdays)", [
            ':idcart'=>$this->getidcart(),
            ':dessessionid'=>$this->getdessessionid(),
            ':iduser'=>$this->getiduser(),
            ':deszipcode'=>$this->getdeszipcode(),
            ':vlfreight'=>$this->getvlfreight(),
            ':nrdays'=>$this->getnrdays()
        ]);

        $this->setData($results[0]);

    }

    public function addProduct(Product $product){
        //Adiciona um produto ao carrinho
		$sql = new Sql();
		$sql->query("INSERT INTO tb_cartsproducts (idcart, idproduct) VALUES(:idcart, :idproduct)", [
			':idcart'=>$this->getidcart(),
			':idproduct'=>$product->getidproduct()
        ]);
        //Atualiza o valor total
		$this->getCalculateTotal();
	}

    public function removeProduct(Product $product, $all = false){
        //Remove de produtos do carrinho
		$sql = new Sql();
        //Remoção de todos os produtos
        if ($all) {
			$sql->query("UPDATE tb_cartsproducts SET dtremoved = NOW() WHERE idcart = :idcart AND idproduct = :idproduct AND dtremoved IS NULL", [
				':idcart'=>$this->getidcart(),
				':idproduct'=>$product->getidproduct()
			]);
		} else {
            //Remoção de apenas uma unidade
			$sql->query("UPDATE tb_cartsproducts SET dtremoved = NOW() WHERE idcart = :idcart AND idproduct = :idproduct AND dtremoved IS NULL LIMIT 1", [
				':idcart'=>$this->getidcart(),
				':idproduct'=>$product->getidproduct()
			]);
        }
        //Atualiza o valor total de item
		$this->getCalculateTotal();
	}
    
    public function getProducts(){
        //Obtém a lista de produtos presentes no carrinho
		$sql = new Sql();
		$rows = $sql->select("
			SELECT b.idproduct, b.desproduct , b.vlprice, b.vlwidth, b.vlheight, b.vllength, b.vlweight, b.desurl, COUNT(*) AS nrqtd, SUM(b.vlprice) AS vltotal 
			FROM tb_cartsproducts a 
			INNER JOIN tb_products b ON a.idproduct = b.idproduct 
			WHERE a.idcart = :idcart AND a.dtremoved IS NULL 
			GROUP BY b.idproduct, b.desproduct , b.vlprice, b.vlwidth, b.vlheight, b.vllength, b.vlweight, b.desurl 
			ORDER BY b.desproduct
		", [
			':idcart'=>$this->getidcart()
		]);
		return Product::checkList($rows);
    }
    
    public function getProductsTotals(){
        //Obtém a soma de todos os dados dos produtos presentes no carrinho, como preço, dimensões e peso.
        $sql = new Sql();

        $results = $sql->select("
        SELECT SUM(vlprice) as vlprice, SUM(vlwidth) as vlwidth, SUM(vlheight) AS vlheight, SUM(vllength) AS vllength, SUM(vlweight) AS vlweight, COUNT(*) AS nrqtd
        FROM tb_products a
        INNER JOIN tb_cartsproducts b ON a.idproduct = b.idproduct
        WHERE b.idcart = :idcart AND dtremoved is NULL;
        ", [
            ':idcart'=>$this->getidcart()
        ]);

        if(count($results) > 0) {

            return $results[0];

        } else {
            
            return [];

        }

    }

    public function setFreight($nrzipcode){
        //Cálculo de frete via correios
        
        $nrzipcode = str_replace('-', '', $nrzipcode);

        $totals = $this->getProductsTotals();
        //Array contendo os campos necessários para o cálculo do frete
        if($totals['nrqtd'] > 0){
            /* @param float $totals['vlheight'] Altura total dos itens (Deve ser maior que 2 e máximo de 105)
               @param float $totals['vlwidth'] Comprimento total dos itens (Deve ser maior que 11 e máximo de 105)
               @param float $totals['vllenght'] Comrpeimtno total dos itens (Deve ser maior que 16 e máximo de 105)
            */
            if($totals['vlheight'] < 2) $totals['vlheight'] = 2;
            if($totals['vllength'] < 16) $totals['vllength'] = 16;
            $qs = http_build_query([
                'nCdEmpresa'=>'',
                'sDsSenha'=>'',
                'nCdServico'=>'40010',
                'sCepOrigem'=>'09853120',
                'sCepDestino'=>$nrzipcode,
                'nVlPeso'=>$totals['vlweight'],
                'nCdFormato'=>'1',
                'nVlComprimento'=>$totals['vllength'],
                'nVlAltura'=>$totals['vlheight'],
                'nVlLargura'=>$totals['vlwidth'],
                'nVlDiametro'=>'0',
                'sCdMaoPropria'=>'S',
                'nVlValorDeclarado'=>$totals['vlprice'],
                'sCdAvisoRecebimento'=>'S'
            ]);

            $xml = simplexml_load_file("http://ws.correios.com.br/calculador/CalcPrecoPrazo.asmx/CalcPrecoPrazo?".$qs);
            
            $result = $xml->Servicos->cServico;

            if($result->MsgErro != ''){

                Cart::setMsgError($result->MsgErro);

            }  else  {
                
                Cart::clearMsgError();

            }

            $this->setnrdays($result->PrazoEntrega);
            $this->setvlfreight(Cart::formatValueToDecimal($result->Valor));
            $this->getdeszipcode($nrzipcode);
            
            $this->save();

            return $result;

        } else {


        }

    }
    
    public static function formatValueToDecimal($value):float {

        $value = str_replace('.', '', $value);
        return str_replace(',', '.', $value);

    }

    public static function setMsgError($msg){

        $_SESSION[Cart::SESSION_ERROR] = $msg;

    }

    public static function getMsgError(){

        $msg = (isset($_SESSION[Cart::SESSION_ERROR])) ? $_SESSION[Cart::SESSION_ERROR] : "";

		Cart::clearMsgError();

		return $msg;

    }

    public static function clearMsgError(){

        $_SESSION[Cart::SESSION_ERROR] = NULL;

    }

    public function updateFreight(){

		if ($this->getdeszipcode() != '') {

			$this->setFreight($this->getdeszipcode());

		}

    }
    
    public function getValues(){

        $this->getCalculateTotal();

        return parent::getValues();

    }

    public function getCalculateTotal(){

        $this->updateFreight();

        $totals = $this->getProductsTotals();

        $this->setvlsubtotal($totals['vlprice']);
        $this->setvltotal($totals['vlprice'] + (float)$this->getvlfreight());

    }

}
    
?>