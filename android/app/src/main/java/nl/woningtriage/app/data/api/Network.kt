package nl.woningtriage.app.data.api

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.jakewharton.retrofit2.converter.kotlinx.serialization.asConverterFactory
import kotlinx.serialization.json.Json
import nl.woningtriage.app.BuildConfig
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
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

    var activeIntakeId: String?
        get() = prefs.getString("active_intake_id", null)
        set(value) {
            prefs.edit().putString("active_intake_id", value).apply()
        }
}

fun createApi(tokenStore: TokenStore): WoningtriageApi {
    val json = Json { ignoreUnknownKeys = true; explicitNulls = false }
    val auth = Interceptor { chain ->
        val request = chain.request()
        val builder = request.newBuilder()
        tokenStore.accessToken?.let { builder.header("Authorization", "Bearer $it") }
        if (isNgrokHost(request.url.host)) {
            builder.header("ngrok-skip-browser-warning", "1")
        }
        chain.proceed(builder.build())
    }
    val logging = HttpLoggingInterceptor().apply {
        level = HttpLoggingInterceptor.Level.BASIC
        redactHeader("Authorization")
    }
    val client = OkHttpClient.Builder()
        .addInterceptor(auth)
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
