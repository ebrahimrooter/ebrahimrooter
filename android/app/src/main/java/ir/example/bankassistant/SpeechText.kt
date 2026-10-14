package ir.example.bankassistant

/**
 * Chat text → what the voice should say (the same rules as ba_speech_text() on
 * the server): no emoji, links or /commands; amounts, Jalali dates and times as
 * Persian words — the phonemizer would read «4,000,000» digit by digit.
 * Checked against the server's output in tools/voice-model/speech_cases.json.
 */
object SpeechText {
    private val months = arrayOf("", "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند")
    private val ones = arrayOf("", "یک", "دو", "سه", "چهار", "پنج", "شش", "هفت", "هشت", "نه", "ده", "یازده", "دوازده", "سیزده",
        "چهارده", "پانزده", "شانزده", "هفده", "هجده", "نوزده")
    private val tens = arrayOf("", "", "بیست", "سی", "چهل", "پنجاه", "شصت", "هفتاد", "هشتاد", "نود")
    private val hundreds = arrayOf("", "صد", "دویست", "سیصد", "چهارصد", "پانصد", "ششصد", "هفتصد", "هشتصد", "نهصد")
    private val scales = arrayOf("", "هزار", "میلیون", "میلیارد", "هزار میلیارد")
    private const val WB = "(?<![0-9A-Za-z_])"   // start of an ASCII word
    private const val NW = "(?![0-9A-Za-z_])"    // end of an ASCII word

    fun numWords(value: Long): String {
        var n = value
        if (n == 0L) return "صفر"
        if (n < 0) return "منفی " + numWords(-n)
        fun below1000(v: Int): String {
            var x = v
            val w = ArrayList<String>()
            if (x >= 100) { w.add(hundreds[x / 100]); x %= 100 }
            if (x >= 20) { w.add(tens[x / 10]); x %= 10 }
            if (x > 0) w.add(ones[x])
            return w.joinToString(" و ")
        }
        val parts = ArrayList<String>()
        var i = 0
        while (n > 0 && i < scales.size) {
            val chunk = (n % 1000).toInt()
            if (chunk != 0) parts.add(if (i == 1 && chunk == 1) "هزار" else (below1000(chunk) + " " + scales[i]).trim())
            n /= 1000
            i++
        }
        return parts.reversed().joinToString(" و ")
    }

    private fun normalize(s: String): String {
        val sb = StringBuilder(s.length)
        for (ch in s) {
            sb.append(when (ch) {
                in '۰'..'۹' -> '0' + (ch - '۰')
                in '٠'..'٩' -> '0' + (ch - '٠')
                'ي' -> 'ی'
                'ك' -> 'ک'
                '‌' -> ' '
                '\r' -> null
                else -> ch
            } ?: continue)
        }
        return sb.toString()
    }

    fun forSpeech(text: String): String {
        var s = normalize(text)
        s = Regex("(?<=\\d)[٬،](?=\\d{3})").replace(s, ",")
        s = Regex("https?://\\S+").replace(s, " ")
        // \b as on the server (ASCII word characters only; Android's own \b also counts Persian letters)
        s = Regex("(^|\\s)/[a-z_]+$NW").replace(s, " ")
        // emoji, arrows, box drawing (as in the server's list); supplementary planes via surrogate pairs
        s = Regex("[\\x{1F000}-\\x{1FAFF}\\x{2600}-\\x{27BF}\\x{2B00}-\\x{2BFF}\\x{FE0F}\\x{200D}\\x{20E3}\\x{2190}-\\x{21FF}\\x{2500}-\\x{257F}]").replace(s, " ")
        s = Regex("$WB(1[34]\\d\\d)/(\\d{1,2})/(\\d{1,2})$NW").replace(s) { m ->
            val mo = m.groupValues[2].toInt()
            if (mo in 1..12) numWords(m.groupValues[3].toLong()) + " " + months[mo] + " " + numWords(m.groupValues[1].toLong()) else m.value
        }
        s = Regex("(ساعت\\s*)?$WB([01]?\\d|2[0-3]):([0-5]\\d)$NW").replace(s) { m ->
            val mi = m.groupValues[3].toInt()
            "ساعت " + numWords(m.groupValues[2].toLong()) + (if (mi != 0) " و " + numWords(mi.toLong()) + " دقیقه" else "")
        }
        s = Regex("\\d{1,3}(?:,\\d{3})+|\\d+").replace(s) { m ->
            val digits = m.value.replace(",", "")
            if (digits.length > 15) " " else numWords(digits.toLong())
        }
        val from = listOf("·", "—", "–", "•", "|", "«", "»", "\"", "(", ")", ":")
        val to = listOf("،", "،", "،", "،", "،", "", "", "", "، ", "، ", "،")
        for (k in from.indices) s = s.replace(from[k], to[k])
        s = Regex("\\s*\\n+\\s*").replace(s, ". ")
        s = Regex("([.،؟!?])(\\s*[.،])+").replace(s, "$1")
        s = Regex("\\s+").replace(s, " ")
        s = Regex("^[\\s.،]+|[\\s.،]+$").replace(s, "")
        return if (s.length > 1200) s.substring(0, 1200) else s
    }
}
