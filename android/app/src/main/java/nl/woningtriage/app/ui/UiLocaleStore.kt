package nl.woningtriage.app.ui

import android.content.Context
import android.content.res.Configuration
import android.os.LocaleList
import java.util.Locale

object UiLocaleStore {
    private const val PREFS = "woningtriage.locale"
    private const val KEY = "ui_locale"

    fun read(context: Context): String {
        val raw = prefs(context).getString(KEY, null)
        return UiLocale.fromTag(raw ?: "nl-NL")
    }

    fun write(context: Context, tag: String) {
        prefs(context).edit().putString(KEY, UiLocale.fromTag(tag)).commit()
    }

    fun appliedTag(context: Context): String {
        val locales = context.resources.configuration.locales
        if (locales.isEmpty) {
            return "nl-NL"
        }
        return UiLocale.fromTag(locales[0].toLanguageTag())
    }

    fun wrap(base: Context): Context {
        val locale = Locale.forLanguageTag(read(base))
        Locale.setDefault(locale)
        val config = Configuration(base.resources.configuration)
        config.setLocale(locale)
        config.setLocales(LocaleList(locale))
        return base.createConfigurationContext(config)
    }

    private fun prefs(context: Context) =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
}
