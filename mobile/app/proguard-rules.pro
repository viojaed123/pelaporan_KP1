# Aturan ProGuard/R8 (saat ini minify dimatikan; disiapkan untuk rilis).
# Model DTO diurai Gson lewat refleksi -> jaga nama kelas & field.
-keep class com.phr.pelaporan.data.** { *; }
-keepattributes Signature
-keepattributes *Annotation*
