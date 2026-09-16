package nl.woningtriage.app.location

import nl.woningtriage.app.data.api.NearbyAddressHint

data class GeocodedPlace(
    val postalCode: String? = null,
    val subThoroughfare: String? = null,
    val premises: String? = null,
    val featureName: String? = null,
    val countryCode: String? = null,
    val countryName: String? = null,
)

object NearbyAddressHints {
    private val houseNumberPattern = Regex("""^(\d{1,5})\s*[-/]?\s*([A-Za-z0-9]{0,12})$""")

    fun fromPlaces(places: List<GeocodedPlace>): List<NearbyAddressHint> {
        val hints = linkedMapOf<String, NearbyAddressHint>()
        for (place in places) {
            if (!isNetherlands(place)) {
                continue
            }
            val postcode = displayPostcode(place.postalCode) ?: continue
            val parsed = parseHouseNumber(place.subThoroughfare)
                ?: parseHouseNumber(place.premises)
                ?: parseHouseNumber(place.featureName)
                ?: continue
            val key = compactPostcode(postcode) + ":" + parsed.first + ":" + (parsed.second ?: "").lowercase()
            if (key in hints) {
                continue
            }
            hints[key] = NearbyAddressHint(postcode, parsed.first, parsed.second)
            if (hints.size >= 8) {
                break
            }
        }
        return hints.values.toList()
    }

    private val postcodePattern = Regex("""^[1-9][0-9]{3}\s*[A-Za-z]{2}$""")

    fun parseHouseNumber(raw: String?): Pair<Int, String?>? {
        val trimmed = raw?.trim().orEmpty()
        if (trimmed.isEmpty() || postcodePattern.matches(trimmed)) {
            return null
        }
        val match = houseNumberPattern.matchEntire(trimmed) ?: return null
        val number = match.groupValues[1].toIntOrNull() ?: return null
        if (number < 1) {
            return null
        }
        val addition = match.groupValues[2].trim().ifBlank { null }
        return number to addition
    }

    private fun isNetherlands(place: GeocodedPlace): Boolean {
        val code = place.countryCode?.trim()?.uppercase().orEmpty()
        if (code == "NL" || code == "NLD") {
            return true
        }
        val name = place.countryName?.trim()?.lowercase().orEmpty()
        return name == "nederland" || name == "netherlands" || name == "the netherlands"
    }

    private fun compactPostcode(postcode: String): String =
        postcode.replace(Regex("""\s+"""), "").uppercase()

    private fun displayPostcode(raw: String?): String? {
        val compact = compactPostcode(raw.orEmpty())
        if (!compact.matches(Regex("^[1-9][0-9]{3}[A-Z]{2}$"))) {
            return null
        }
        return compact.substring(0, 4) + " " + compact.substring(4)
    }
}
