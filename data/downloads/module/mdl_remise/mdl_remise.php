<?php
/**
 * 
 * @copyright	2000-2007 LOCKON CO.,LTD. All Rights Reserved.
 * @version	CVS: $Id: mdl_remise.php 1.0 2007-02-05 06:08:28Z inoue $
 * @link		http://www.lockon.co.jp/
 *
 */
require_once(MODULE_PATH . "mdl_remise/mdl_remise.inc");

//ページ管理クラス
class LC_Page {
	//コンストラクタ
	function LC_Page() {
		//メインテンプレートの指定
		$this->tpl_mainpage = MODULE_PATH . 'mdl_remise/mdl_remise.tpl';
		$this->tpl_subtitle = 'ルミーズ決済モジュール';
		global $arrPayment;
		$this->arrPayment = $arrPayment;
		global $arrCredit;
		$this->arrCredit = $arrCredit;
		global $arrCreditDivide;
		$this->arrCreditDivide = $arrCreditDivide;
		global $arrConvenience;
		$this->arrConvenience = $arrConvenience;
		global $arrMobileConvenience;
		$this->arrMobileConvenience = $arrMobileConvenience;
	}
}
$objPage = new LC_Page();
$objView = new SC_AdminView();
$objQuery = new SC_Query();

// ルミーズカードクレジット決済結果通知処理
lfRemiseCreditResultCheck();

// コンビニ入金チェック
lfRemiseConveniCheck();

// 認証確認
$objSess = new SC_Session();
sfIsSuccess($objSess);

// パラメータ管理クラス
$objFormParam = new SC_FormParam();
$objFormParam = lfInitParam($objFormParam);

// POST値の取得
$objFormParam->setParam($_POST);

// 汎用項目を追加(必須！！)
sfAlterMemo();

switch($_POST['mode']) {
case 'edit':
	// 入力エラー判定
	$objPage->arrErr = lfCheckError();

	// エラーなしの場合にはデータを更新	
	if (count($objPage->arrErr) == 0) {
		// データ更新
		lfUpdPaymentDB();
		
		// javascript実行
		$objPage->tpl_onload = 'alert("登録完了しました。\n基本情報＞支払方法設定より詳細設定をしてください。"); window.close();';
	}
	break;
case 'module_del':
	// 汎用項目の存在チェック
	if (sfColumnExists("dtb_payment", "memo01")) {
		// データの削除フラグをたてる
		$objQuery->query("UPDATE dtb_payment SET del_flg = 1 WHERE module_id = ?", array(MDL_REMISE_ID));
	}
	break;
default:
	// データのロード
	lfLoadData();
	break;
}

$objPage->arrForm = $objFormParam->getFormParamList();

$objView->assignobj($objPage);					//変数をテンプレートにアサインする
$objView->display($objPage->tpl_mainpage);		//テンプレートの出力
//-------------------------------------------------------------------------------------------------------
/* パラメータ情報の初期化 */
function lfInitParam($objFormParam) {
	$objFormParam->addParam("加盟店コード", "code", INT_LEN, "KVa", array("EXIST_CHECK", "MAX_LENGTH_CHECK"));
	$objFormParam->addParam("ホスト番号", "host_id", INT_LEN, "KVa", array("EXIST_CHECK", "MAX_LENGTH_CHECK", "NUM_CHECK"));
	$objFormParam->addParam("クレジット接続先URL(PC)", "credit_url", URL_LEN, "KVa", array("EXIST_CHECK", "MAX_LENGTH_CHECK", "URL_CHECK"));
	$objFormParam->addParam("クレジット接続先URL(モバイル)", "mobile_credit_url");
	$objFormParam->addParam("支払い方法", "credit_method");
	$objFormParam->addParam("分割回数", "credit_divide");
	$objFormParam->addParam("オプション", "payment");
	$objFormParam->addParam("コンビニ接続先URL(PC)", "convenience_url");
	$objFormParam->addParam("コンビニ接続先URL(モバイル)", "mobile_convenience_url");
	return $objFormParam;
}

// エラーチェックを行う
function lfCheckError(){
	global $objFormParam;
	
	$arrErr = $objFormParam->checkError();
	
	// 利用クレジット、利用コンビニのエラーチェック
	$arrChkPay = $_POST["payment"];

	// クレジットの支払い方法
	if (count($_POST["credit_method"]) <= 0) {
		$arrErr["credit_method"] = "支払い方法が選択されていません。<br />";
	}

	// 利用コンビニ
	if (isset($arrChkPay)) {
		if ($_POST["convenience_url"] == "" && $_POST["mobile_convenience_url"] == "") {
			$arrErr["convenience_url"] = "コンビニ接続先URL(PC)またはコンビニ接続先URL(モバイル)が入力されていません。<br />";
		}
	}

	return $arrErr;
}

// 登録データを読み込む
function lfLoadData(){
	global $objFormParam;
	
	//データを取得
	$arrRet = lfGetPaymentDB(" AND del_flg = '0'");

	// 値をセット
	$objFormParam->setParam($arrRet[0]);

	// 画面表示用にデータを変換
	$arrDisp = array();
	$arrDisp["payment"][0] = 0;

	foreach($arrRet as $key => $val) {
		// クレジットの決済区分を取得
		if($val["payment"] == 1) {
			$credit = $val["payment_code"];
			$arrDisp["credit_divide"] = $val["credit_divide"];
		}

		// コンビニの決済区分を取得
		if($val["payment"] == 2) {
			$arrDisp["convenience"] = $val["convenience"];
			$arrDisp["payment"][0] = 1;
			$arrDisp["convenience_url"] = $val["convenience_url"];
			$arrDisp["mobile_convenience_url"] = $val["mobile_convenience_url"];
		}
	}

	$objFormParam->setParam($arrDisp);
	
	// クレジット支払い区分
	$objFormParam->splitParamCheckBoxes("credit_method");
	
	// コンビニ
	$objFormParam->splitParamCheckBoxes("convenience");

	$objFormParam->setParam($arrCredit);
}

// DBからデータを取得する
function lfGetPaymentDB($where = "", $arrWhereVal = array()){
	global $objQuery;
	
	$arrVal = array(MDL_REMISE_ID);
	$arrVal = array_merge($arrVal, $arrWhereVal);
	
	$arrRet = array();
	$sql = "SELECT 
				module_id, 
				memo01 as code, 
				memo02 as host_id, 
				memo03 as payment,
				memo04 as credit_url,
				memo05 as convenience_url,
				memo06 as mobile_credit_url,
				memo07 as mobile_convenience_url,
				memo08 as credit_method,
				memo09 as credit_divide
			FROM dtb_payment WHERE module_id = ? " . $where;
	$arrRet = $objQuery->getall($sql, $arrVal);

	return $arrRet;
}


// データの更新処理
function lfUpdPaymentDB(){
	global $objQuery;
	global $objSess;
	
	// 支払い方法にチェックが入っている場合は、ハイフン区切りに編集する
	$convCnt = count($_POST["credit_method"]);
	if ($convCnt > 0) {
		$credit_method = $_POST["credit_method"][0];
		for ($i = 1 ; $i < $convCnt ; $i++) {
			$credit_method .= "-" . $_POST["credit_method"][$i];
		}
	}

	// del_flgを削除にしておく
	$del_sql = "UPDATE dtb_payment SET del_flg = 1 WHERE module_id = ? ";
	$arrDel = array(MDL_REMISE_ID);
	$objQuery->query($del_sql, $arrDel);

	$arrEntry = array('1');

	if (count($_POST["payment"]) > 0) {
		$arrEntry[] = '2';
	}

	foreach($arrEntry as $key => $val){
		// ランクの最大値を取得する
		$max_rank = $objQuery->getone("SELECT max(rank) FROM dtb_payment");

		// 支払方法データを取得			
		$arrPaymentData = lfGetPaymentDB("AND memo03 = ?", array($val));

		// クレジット決済登録
		if($val == 1) {

			$arrData = array(
				"payment_method" => "remiseクレジット"
				,"fix" => 3
				,"creator_id" => $objSess->member_id
				,"create_date" => "now()"
				,"update_date" => "now()"
				,"upper_rule" => REMISE_CREDIT_UPPER
				,"module_id" => MDL_REMISE_ID
				,"module_path" => MODULE_PATH . "mdl_remise/card.php"
				,"memo01" => $_POST["code"]
				,"memo02" => $_POST["host_id"]
				,"memo03" => $val
				,"memo04" => $_POST["credit_url"]
				,"memo06" => $_POST["mobile_credit_url"]
				,"memo08" => $credit_method
				,"memo09" => REMISE_PAYMENT_DIVIDE_MAX
				,"del_flg" => "0"
				,"charge_flg" => "2"
				,"upper_rule_max" => REMISE_CREDIT_UPPER
				
			);
		}

		// コンビニにチェックが入っていればコンビニを登録する
		if($val == 2) {
			
			$arrData = array(
				"payment_method" => "remiseコンビニ"
				,"fix" => 3
				,"creator_id" => $objSess->member_id
				,"create_date" => "now()"
				,"update_date" => "now()"
				,"upper_rule" => REMISE_CONVENIENCE_UPPER
				,"module_id" => MDL_REMISE_ID
				,"module_path" => MODULE_PATH . "mdl_remise/convenience.php"
				,"memo01" => $_POST["code"]
				,"memo02" => $_POST["host_id"]
				,"memo03" => $val
				,"memo05" => $_POST["convenience_url"]
				,"memo07" => $_POST["mobile_convenience_url"]
				,"del_flg" => "0"
				,"charge_flg" => "1"
				,"upper_rule_max" => REMISE_CREDIT_UPPER
			);
		}

		// データが存在していればUPDATE、無ければINSERT
		if (count($arrPaymentData) > 0) {
			$objQuery->update("dtb_payment", $arrData, " module_id = '" . MDL_REMISE_ID . "' AND memo03 = '" . $val ."'");
		} else {
			$arrData["rank"] = $max_rank + 1;
			$objQuery->insert("dtb_payment", $arrData);
		}
	}
}

// ルミーズカードクレジット決済結果通知処理
function lfRemiseCreditResultCheck(){
	global $objQuery;
	
	$log_path = DATA_PATH . "logs/remise_card_result.log";
	gfPrintLog("remise card result : ".$_POST["X-TRANID"] , $log_path);
	
	// TRAN_ID を指定されていて、カード情報がある場合
	if (isset($_POST["X-TRANID"]) && isset($_POST["X-PARTOFCARD"])) {
		
		gfPrintLog("remise card result start----------", $log_path);
		foreach($_POST as $key => $val){
			gfPrintLog( "\t" . $key . " => " . $val, $log_path);
		}
		gfPrintLog("remise credit result end  ----------", $log_path);

		// 請求番号
		$order_id = $_POST["X-S_TORIHIKI_NO"];
		$payment_total = $_POST["X-TOTAL"];
		
		gfPrintLog("order_id : ".$order_id, $log_path);
		gfPrintLog("payment_total : ".$payment_total, $log_path);

		$arrTempOrder = $objQuery->getall("SELECT payment_total FROM dtb_order_temp WHERE order_id = ? ", array($order_id));

		// 金額の相違
		if (count($arrTempOrder) > 0) {
			gfPrintLog("ORDER payment_total : ".$arrTempOrder[0]['payment_total'], $log_path);
			if ($arrTempOrder[0]['payment_total'] != $payment_total) {
				print("ERROR");
				exit;
			}
			print("<SDBKDATA>STATUS=800</SDBKDATA>");
			exit;
		}
		print("ERROR");
		exit;
	}
}

// コンビニ入金確認処理
function lfRemiseConveniCheck(){
	global $objQuery;
	
	$log_path = DATA_PATH . "logs/remise_cv_charge.log";
	gfPrintLog("remise conveni result : ".$_POST["JOB_ID"] , $log_path);
	
	// JOB_ID を指定されていて且つ、入金済みの場合
	if($_POST["JOB_ID"] != "" and $_POST["REC_FLG"] == 1 and $_POST["S_TORIHIKI_NO"] != ""){
		
		// POSTの内容を全てログ保存
		gfPrintLog("remise conveni charge start----------", $log_path);
		foreach($_POST as $key => $val){
			gfPrintLog( "\t" . $key . " => " . $val, $log_path);
		}
		gfPrintLog("remise conveni charge end  ----------", $log_path);
		
		// ステータスを入金済みに変更する
		$sql = "UPDATE dtb_order SET status = 6, update_date = now() WHERE order_id = ? AND memo04 = ? ";
		$objQuery->query($sql, array($_POST["S_TORIHIKI_NO"], $_POST["JOB_ID"]));
		
		//応答結果を表示
		print("<SDBKDATA>STATUS=800</SDBKDATA>");
		exit;
	}
}

?>