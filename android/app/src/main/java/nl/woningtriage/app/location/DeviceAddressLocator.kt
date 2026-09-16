package nl.woningtriage.app.location

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.location.Geocoder
import android.location.LocationManager
import android.os.Build
import android.os.CancellationSignal
import androidx.core.content.ContextCompat
import kotlinx.coroutines.TimeoutCancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeout
import nl.woningtriage.app.data.api.NearbyAddressHint
import java.util.Locale
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

data class GeoPoint(val latitude: Double, val longitude: Double)

class DeviceAddressLocator(private val context: Context) {
    fun hasPermission(): Boolean {
        val fine = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION)
        val coarse = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION)
        return fine == PackageManager.PERMISSION_GRANTED || coarse == PackageManager.PERMISSION_GRANTED
    }

    @SuppressLint("MissingPermission")
    suspend fun currentLocation(): GeoPoint = try {
        withTimeout(15_000) {
            suspendCancellableCoroutine { cont ->
                val manager = context.getSystemService(Context.LOCATION_SERVICE) as LocationManager
                val provider = when {
                    manager.isProviderEnabled(LocationManager.GPS_PROVIDER) -> LocationManager.GPS_PROVIDER
                    manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER) -> LocationManager.NETWORK_PROVIDER
                    else -> {
                        cont.resumeWithException(IllegalStateException("location_unavailable"))
                        return@suspendCancellableCoroutine
                    }
                }
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                    val cancel = CancellationSignal()
                    cont.invokeOnCancellation { cancel.cancel() }
                    manager.getCurrentLocation(provider, cancel, context.mainExecutor) { location ->
                        val fix = location ?: manager.getLastKnownLocation(provider)
                        if (fix != null) {
                            cont.resume(GeoPoint(fix.latitude, fix.longitude))
                        } else {
                            cont.resumeWithException(IllegalStateException("location_unavailable"))
                        }
                    }
                } else {
                    val last = manager.getLastKnownLocation(provider)
                        ?: manager.getLastKnownLocation(LocationManager.NETWORK_PROVIDER)
                    if (last != null) {
                        cont.resume(GeoPoint(last.latitude, last.longitude))
                    } else {
                        cont.resumeWithException(IllegalStateException("location_unavailable"))
                    }
                }
            }
        }
    } catch (_: TimeoutCancellationException) {
        throw IllegalStateException("location_unavailable")
    }

    suspend fun nearbyAddresses(latitude: Double, longitude: Double): List<NearbyAddressHint> =
        withContext(Dispatchers.IO) {
            if (!Geocoder.isPresent()) {
                return@withContext emptyList()
            }
            val geocoder = Geocoder(context, Locale("nl", "NL"))
            val results = runCatching { geocoder.getFromLocation(latitude, longitude, 8) }.getOrNull().orEmpty()
            NearbyAddressHints.fromPlaces(
                results.map { address ->
                    GeocodedPlace(
                        postalCode = address.postalCode,
                        subThoroughfare = address.subThoroughfare,
                        premises = address.premises,
                        featureName = address.featureName,
                        countryCode = address.countryCode,
                        countryName = address.countryName,
                    )
                },
            )
        }
}
