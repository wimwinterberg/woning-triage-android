package nl.woningtriage.app.data.api

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.jakewharton.retrofit2.converter.kotlinx.serialization.asConverterFactory
import kotlinx.serialization.json.Json
import nl.woningtriage.app.BuildConfig
import nl.woningtriage.app.domain.ApiErrorEnvelope
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.ResponseBody.Companion.toResponseBody
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import java.util.UUID
import java.util.concurrent.TimeUnit

class TokenStore(context: Context) {
    private val prefs = EncryptedSharedPreferences.create(
        context,
        "woningtriage.secure",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    var accessToken: String?
        get() = prefs.getString("access_token", null)
        set(value) {
            prefs.edit().putString("access_token", value).apply()
        }

    var uiLocale: String
        get() = prefs.getString("ui_locale", null) ?: "nl-NL"
        set(value) {
            prefs.edit().putString("ui_locale", value).apply()
        }

    var activeIntakeId: String?
        get() = prefs.getString("active_intake_id", null)
        set(value) {
            prefs.edit().putString("active_intake_id", value).apply()
        }

    fun sessionIdempotencyKey(): String {
        val existing = prefs.getString("session_idempotency_key", null)
        if (existing != null) {
            return existing
        }
        val created = UUID.randomUUID().toString()
        prefs.edit().putString("session_idempotency_key", created).apply()
        return created
    }
}

fun createApi(tokenStore: TokenStore): WoningtriageApi {
    val json = Json {
        ignoreUnknownKeys = true
        explicitNulls = false
        encodeDefaults = true
        coerceInputValues = true
    }
    val auth = Interceptor { chain ->
        val request = chain.request()
        val builder = request.newBuilder()
        tokenStore.accessToken?.let { builder.header("Authorization", "Bearer $it") }
        if (isNgrokHost(request.url.host)) {
            builder.header("ngrok-skip-browser-warning", "1")
        }
        chain.proceed(builder.build())
    }
    val firstJson = Interceptor { chain ->
        val response = chain.proceed(chain.request())
        val body = response.body ?: return@Interceptor response
        val media = body.contentType()
        if (media?.subtype != "json") {
            return@Interceptor response
        }
        val raw = body.string()
        val trimmed = firstJsonDocument(raw)
        response.newBuilder()
            .body(trimmed.toResponseBody(media))
            .build()
    }
    val logging = HttpLoggingInterceptor().apply {
        level = HttpLoggingInterceptor.Level.BASIC
        redactHeader("Authorization")
    }
    val client = OkHttpClient.Builder()
        .addInterceptor(auth)
        .addInterceptor(firstJson)
        .addInterceptor(logging)
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(40, TimeUnit.SECONDS)
        .build()
    return Retrofit.Builder()
        .baseUrl(BuildConfig.BACKEND_URL)
        .client(client)
        .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
        .build()
        .create(WoningtriageApi::class.java)
}

internal fun isNgrokHost(host: String): Boolean =
    host.contains("ngrok", ignoreCase = true)

internal fun firstJsonDocument(raw: String): String {
    val start = raw.indexOfFirst { !it.isWhitespace() }
    if (start < 0) {
        return raw
    }
    val open = raw[start]
    if (open != '{' && open != '[') {
        return raw
    }
    val close = if (open == '{') '}' else ']'
    var depth = 0
    var inString = false
    var escape = false
    for (i in start until raw.length) {
        val c = raw[i]
        if (inString) {
            if (escape) {
                escape = false
            } else if (c == '\\') {
                escape = true
            } else if (c == '"') {
                inString = false
            }
            continue
        }
        when (c) {
            '"' -> inString = true
            open -> depth++
            close -> {
                depth--
                if (depth == 0) {
                    return raw.substring(start, i + 1)
                }
            }
        }
    }
    return raw
}

internal fun parseApiErrorMessage(raw: String): String? {
    val json = runCatching {
        Json { ignoreUnknownKeys = true }.decodeFromString(ApiErrorEnvelope.serializer(), raw.trim())
    }.getOrNull() ?: return null
    return json.error.message.takeIf { it.isNotBlank() }
}

internal fun userFacingApiError(error: Throwable): String {
    if (error is retrofit2.HttpException) {
        val raw = error.response()?.errorBody()?.string().orEmpty()
        parseApiErrorMessage(firstJsonDocument(raw))?.let { return it }
        return "De server wees het verzoek af (${error.code()})."
    }
    return error.message ?: "Er ging iets mis."
}
