package com.asyafresh.nfcuidtani

import android.app.Activity
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.graphics.Color
import android.graphics.Typeface
import android.nfc.NfcAdapter
import android.nfc.Tag
import android.nfc.tech.MifareClassic
import android.nfc.tech.NfcA
import android.os.Bundle
import android.view.View
import android.view.ViewGroup
import android.widget.Button
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import java.math.BigInteger

/**
 * FAZ 0 TEŞHİS UYGULAMASI — Asya Fresh PDKS
 *
 * TEK İŞİ: android.nfc.Tag.getId()'nin döndürdüğü HAM bayt dizisini
 * HİÇBİR ÇEVİRİ YAPMADAN göstermek.
 *
 * Bu bir üretim uygulaması DEĞİLDİR. Ağa çıkmaz, izin istemez (NFC dışında),
 * hiçbir veri saklamaz, hiçbir sunucuya bağlanmaz.
 *
 * Cevaplanacak soru: aynı fiziksel kart için USB HID okuyucu 631799511
 * (= 0x25A87ED7) yazıyor. getId() "25 A8 7E D7" mi yoksa "D7 7E A8 25" mi
 * döndürüyor? Üçüncü parti uygulamalar ikisini de gösterdi; kanon bu
 * ölçümle sabitlenecek.
 */
class MainActivity : Activity() {

    private var nfc: NfcAdapter? = null
    private lateinit var cikti: TextView
    private var sonRapor: String = ""

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val kok = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(32, 48, 32, 32)
            setBackgroundColor(Color.WHITE)
        }

        kok.addView(TextView(this).apply {
            text = "NFC UID TANI — Faz 0"
            textSize = 20f
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(Color.parseColor("#1d6cf0"))
        })

        kok.addView(TextView(this).apply {
            text = "Kartı telefonun arkasına okutun.\n" +
                   "getId() çıktısı HİÇ çevrilmeden gösterilir."
            textSize = 13f
            setTextColor(Color.DKGRAY)
            setPadding(0, 12, 0, 20)
        })

        cikti = TextView(this).apply {
            text = "\n⏳ Kart bekleniyor…\n"
            textSize = 14f
            typeface = Typeface.MONOSPACE
            setTextColor(Color.BLACK)
            setTextIsSelectable(true)
        }
        kok.addView(cikti)

        kok.addView(Button(this).apply {
            text = "SONUCU KOPYALA"
            setOnClickListener {
                if (sonRapor.isEmpty()) {
                    Toast.makeText(this@MainActivity, "Önce kart okutun", Toast.LENGTH_SHORT).show()
                } else {
                    val cb = getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                    cb.setPrimaryClip(ClipData.newPlainText("nfc_uid_tani", sonRapor))
                    Toast.makeText(this@MainActivity, "Panoya kopyalandı", Toast.LENGTH_SHORT).show()
                }
            }
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT
            ).apply { topMargin = 32 }
        })

        setContentView(ScrollView(this).apply { addView(kok) })

        nfc = NfcAdapter.getDefaultAdapter(this)
        if (nfc == null) {
            cikti.text = "\n✗ Bu cihazda NFC donanımı YOK.\n"
        }
    }

    override fun onResume() {
        super.onResume()
        val a = nfc ?: return
        if (!a.isEnabled) {
            cikti.text = "\n✗ NFC KAPALI — Ayarlar'dan açın.\n"
            return
        }
        // enableReaderMode: kapı terminali için doğru yöntem (foreground dispatch değil).
        // SKIP_NDEF_CHECK → sistem NDEF ayrıştırmaya çalışmaz: daha hızlı, sistem sesi yok.
        a.enableReaderMode(
            this,
            { tag -> runOnUiThread { goster(tag) } },
            NfcAdapter.FLAG_READER_NFC_A or
            NfcAdapter.FLAG_READER_NFC_B or
            NfcAdapter.FLAG_READER_NFC_F or
            NfcAdapter.FLAG_READER_NFC_V or
            NfcAdapter.FLAG_READER_SKIP_NDEF_CHECK,
            null
        )
    }

    override fun onPause() {
        super.onPause()
        nfc?.disableReaderMode(this)
    }

    private fun goster(tag: Tag) {
        val id: ByteArray = tag.id                       // ← ÖLÇÜLEN TEK ŞEY

        val hamBayt  = id.joinToString(" ") { String.format("%02X", it) }
        val hexApi   = id.joinToString("")  { String.format("%02X", it) }
        val hexTers  = id.reversedArray().joinToString("") { String.format("%02X", it) }

        // İşaretsiz ondalık: BigInteger(1, ...) işaret bitini bastırır
        val decApi  = if (id.isNotEmpty()) BigInteger(1, id).toString() else "-"
        val decTers = if (id.isNotEmpty()) BigInteger(1, id.reversedArray()).toString() else "-"

        var atqa = "-"; var sak = "-"
        NfcA.get(tag)?.let { n ->
            atqa = n.atqa.joinToString("") { String.format("%02X", it) }
            sak  = String.format("%02X", n.sak)
        }
        val mifare = if (MifareClassic.get(tag) != null) "EVET" else "hayır"
        val techs  = tag.techList.joinToString("\n                 ") { it.substringAfterLast('.') }

        // Beklenti kontrolü: test kartının USB değeri 631799511 = 0x25A87ED7
        val beklenen = "25A87ED7"
        val karar = when {
            hexApi.equals(beklenen, true)  ->
                "✓ getId() = USB ile AYNI YÖN\n  KANON = getId() sırası (çevirme YOK)"
            hexTers.equals(beklenen, true) ->
                "⚠ getId() USB'nin TERSİ\n  KANON = getId() TERS çevrilmiş hâli"
            else ->
                "ℹ Bu kart, 631799511 numaralı test kartı değil.\n" +
                "  Karşılaştırma için AYNI kartı okutun."
        }

        sonRapor = buildString {
            appendLine("=== NFC UID TANI — Faz 0 ===")
            appendLine("UID uzunluğu   : ${id.size} bayt")
            appendLine("getId() ham    : $hamBayt")
            appendLine("HEX (API sırası): $hexApi")
            appendLine("HEX (ters)     : $hexTers")
            appendLine("Ondalık (API)  : $decApi")
            appendLine("Ondalık (ters) : $decTers")
            appendLine("ATQA / SAK     : $atqa / $sak")
            appendLine("MifareClassic  : $mifare")
            appendLine("Tech listesi   : ${tag.techList.joinToString(", ") { it.substringAfterLast('.') }}")
            appendLine()
            appendLine("Beklenen (USB 631799511) : $beklenen")
            appendLine(karar.replace("\n  ", " / "))
        }

        cikti.text = """

UID uzunluğu   : ${id.size} bayt

getId() ham    : $hamBayt
HEX (API sırası): $hexApi
HEX (ters)     : $hexTers

Ondalık (API)  : $decApi
Ondalık (ters) : $decTers

ATQA / SAK     : $atqa / $sak
MifareClassic  : $mifare
Tech listesi   : $techs

──────────────────────────
USB okuyucu    : 631799511
yani beklenen  : $beklenen

$karar
"""
        cikti.setTextColor(
            if (karar.startsWith("✓")) Color.parseColor("#1f9d5b")
            else if (karar.startsWith("⚠")) Color.parseColor("#d18a13")
            else Color.BLACK
        )
    }
}
